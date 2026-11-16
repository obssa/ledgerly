<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Dimension;
use App\Domain\Ledger\Money;
use App\Domain\Reports\ReceivablesReport;
use App\Domain\Reports\ReportService;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LedgerController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /** The chart of accounts, without balances: every role reads it, because every document form names accounts. */
    public function accounts(): JsonResponse
    {
        $this->authorizeCompany('company.view');

        return response()->json(['data' => Account::query()->orderBy('code')->get()]);
    }

    public function trialBalance(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']])['as_of'];

        return response()->json(['data' => $reports->trialBalance($this->current->id(), $asOf)]);
    }

    /**
     * General ledger for a period (Module 6): every account's opening, lines with running balance and closing; or one
     * account (account transactions); optionally one location, class or project. Ids are loaded through the company scope.
     */
    public function generalLedger(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['nullable', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'class_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
        ]);
        if (isset($q['account_id']) && ! Account::query()->whereKey($q['account_id'])->exists()) {
            throw ValidationException::withMessages(['account_id' => ['Choose an account of this company.']]);
        }
        $dimensions = [];
        foreach (Dimension::COLUMNS as $kind => $column) {
            if (! empty($q[$column])) {
                if (! Dimension::query()->where(['id' => $q[$column], 'kind' => $kind])->exists()) {
                    throw ValidationException::withMessages([$column => ["Choose a {$kind} of this company."]]);
                }
                $dimensions[$column] = $q[$column];
            }
        }
        $company = $this->current->get();

        return response()->json(['from' => $q['from'], 'to' => $q['to'], 'currency' => $company->base_currency,
            ...$reports->generalLedger($company->getKey(), $company->base_currency, $q['from'], $q['to'], $q['account_id'] ?? null, $dimensions)]);
    }

    /** Balance sheet; `equation.balanced` reports whether Assets = Liabilities + Equity holds. */
    public function balanceSheet(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']])['as_of'];
        $company = $this->current->get();

        return response()->json($reports->balanceSheet($company->getKey(), $company->base_currency, $asOf));
    }

    /** What customers owe today by age, with credit not yet used; each customer's total is their receivable balance. */
    public function arAgeing(Request $request, ReceivablesReport $receivables): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? app(CurrentCompany::class)->today();
        $company = $this->current->get();

        return response()->json($receivables->ageing($company->getKey(), Account::system('accounts_receivable')->getKey(), $company->base_currency, $asOf));
    }

    /** AP ageing: open bills by days overdue; each total is what the company owes that supplier on accounts payable. */
    public function apAgeing(Request $request, ReceivablesReport $report): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? app(CurrentCompany::class)->today();
        $company = $this->current->get();

        return response()->json($report->ageing($company->getKey(), Account::system('accounts_payable')->getKey(), $company->base_currency, $asOf, 'bill'));
    }

    /**
     * Inventory valuation at a date: quantity and value per item and warehouse from the stock moves, next to the
     * inventory account in the ledger. They are equal by construction; a difference would mean a posting outside the
     * stock ledger (a manual journal on the inventory account) and is shown, not hidden.
     */
    public function inventoryValuation(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $company = $this->current->get();
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? $this->current->today();
        $base = $company->base_currency;

        $rows = DB::table('stock_moves as m')->join('items as i', 'i.id', '=', 'm.item_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.company_id', $company->getKey())->where('m.moved_on', '<=', $asOf)
            ->groupBy('m.item_id', 'i.name', 'i.sku', 'i.unit', 'm.warehouse_id', 'w.name')
            ->orderBy('i.name')->orderBy('w.name')
            ->selectRaw('m.item_id, i.name, i.sku, i.unit, m.warehouse_id, w.name AS warehouse, sum(m.quantity)::numeric(18,2)::text AS quantity, sum(m.amount)::numeric(20,2)::text AS value')
            ->get()->filter(fn ($r) => $r->quantity !== '0.00' || $r->value !== '0.00')->values();
        $stock = $rows->reduce(fn (Money $s, $r) => $s->plus(Money::of($r->value, $base)), Money::zero($base));
        $ledger = Money::of((string) DB::table('journal_lines')->where(['company_id' => $company->getKey(), 'account_id' => Account::system('inventory')->getKey()])
            ->where('entry_date', '<=', $asOf)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b'), $base);

        return response()->json(['as_of' => $asOf, 'rows' => $rows, 'stock_value' => $stock->toDecimalString(),
            'inventory_account' => $ledger->toDecimalString(), 'difference' => $ledger->minus($stock)->toDecimalString()]);
    }

    public function binCard(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($reports->binCard($this->current->id(), $q['item_id'], $q['warehouse_id'], $q['from'], $q['to']));
    }

    public function profitAndLoss(Request $request, ReportService $reports): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'date_format:Y-m-d', 'required_with:compare_from'],
        ]);

        $company = $this->current->get();

        return response()->json($reports->profitAndLoss(
            $company->getKey(), $company->base_currency, $q['from'], $q['to'], $q['compare_from'] ?? null, $q['compare_to'] ?? null,
        ));
    }
}
