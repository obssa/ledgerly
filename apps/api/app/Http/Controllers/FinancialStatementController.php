<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\FinancialStatements;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 10: the cash flow statement, the statement of changes in equity and the financial health pack, for any period
 * (the current fiscal year to date by default). Read from the ledger by App\Domain\Reports\FinancialStatements.
 */
final class FinancialStatementController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly FinancialStatements $statements)
    {
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        [$from, $to] = $this->period($request);
        // Indirect by default (from the profit); direct shows the cash received and paid by what it was for (Module 10).
        $method = $request->validate(['method' => ['nullable', 'in:indirect,direct']])['method'] ?? 'indirect';
        $statement = $method === 'direct' ? $this->statements->directCashFlow($this->current->id(), $from, $to) : $this->statements->cashFlow($this->current->id(), $from, $to);

        return response()->json([...$statement, 'currency' => $this->current->get()->base_currency]);
    }

    /** Module 10: the notes to the financial statements for the period. */
    public function notes(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        [$from, $to] = $this->period($request);

        return response()->json($this->statements->notes($this->current->id(), $from, $to));
    }

    public function changesInEquity(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        [$from, $to] = $this->period($request);

        return response()->json([...$this->statements->changesInEquity($this->current->id(), $from, $to), 'currency' => $this->current->get()->base_currency]);
    }

    public function health(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        [$from, $to] = $this->period($request);

        return response()->json([...$this->statements->health($this->current->id(), $from, $to), 'currency' => $this->current->get()->base_currency]);
    }

    /** @return array{0: string, 1: string} from and to: the current fiscal year to today unless given */
    private function period(Request $request): array
    {
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $q['to'] ?? $this->current->today();
        $from = $q['from'] ?? (DB::table('fiscal_years')->where('company_id', $this->current->id())->where('starts_on', '<=', $to)->where('ends_on', '>=', $to)->value('starts_on')
            ?? (new \DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d'));
        // Any span, "All dates" included (the web app sends the widest dates for an open end): it ends on or after it starts.
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['A report ends on or after it starts.']]);
        }

        return [(string) $from, $to];
    }

    /** Module 5: the cash forecast, 4 to 26 weeks (13 by default) from today: what is held, and what is due in and out. */
    public function cashForecast(Request $request, \App\Domain\Banking\CashForecast $forecast): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['weeks' => ['nullable', 'integer', 'min:4', 'max:26']]);

        return response()->json($forecast->weeks($this->current->id(), $this->current->today(), (int) ($q['weeks'] ?? 13)));
    }

    /** Module 10: the home page's key figures: cash, what customers owe and what is owed, this month against last. */
    public function kpis(\App\Domain\Reports\Kpis $kpis): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $company = $this->current->get();

        return response()->json($kpis->figures($company->getKey(), $company->base_currency, $this->current->today()));
    }
}
