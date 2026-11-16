<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Ledger\Models\Account;
use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\InvoiceWorkflow;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 3, expenses paid on the spot: fuel, utilities, small purchases paid by cash, card, bank or wallet. Recorded
 * and posted in one step (Dr cost and input VAT, Cr the account paid from), with the company's own number (EXP-) and
 * the receipt's number as the reference. A mistake is voided (the entry is reversed) and entered again.
 */
final class ExpenseController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    private function present(Document $expense, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$expense->toArray(),
            'can' => ['void' => (bool) $this->current->membership()?->can('transactions.edit')],
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('expenses.view');
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Document::query()->where('type', 'expense')
            ->with(['contact:id,name', 'paidFrom:id,code,name'])
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->orderByDesc('issue_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Document $expense): JsonResponse
    {
        $this->authorizeCompany('expenses.view');

        return $this->present($expense->load(['lines', 'contact:id,name,tax_id', 'paidFrom:id,code,name']));
    }

    /** Saves and posts at once: the money has already been spent. */
    public function store(Request $request, SaveInvoiceDraft $save, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('expenses.create');
        $data = $request->validate([
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('is_vendor', true)],
            'paid_from_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')
                ->where(fn (Builder $q) => $q->where('is_active', true)->whereIn('subtype', ['bank', 'cash', 'mobile_money']))],
            'payment_method' => ['required', Rule::in(PaymentReceivedController::METHODS)],
            'reference' => ['nullable', 'string', 'max:60'],   // the receipt's number
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'prices_include_tax' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('is_active', true)
                ->where(fn (Builder $w) => $w->where('type', 'expense')->orWhere(fn (Builder $a) => $a->where('type', 'asset')->whereIn('subtype', ['fixed_asset', 'prepayment']))))],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->whereIn('kind', ['vat', 'tot'])],
        ], ['lines.*.account_id.exists' => 'Choose an expense account, or a fixed asset or prepayment account.']);

        // Loaded through the company-scoped model (CLAUDE.md rule 4); the account's currency is the expense's.
        $account = Account::query()->findOrFail($data['paid_from_account_id']);
        if (($account->currency ?? $this->current->get()->base_currency) !== $data['currency']) {
            throw ValidationException::withMessages(['paid_from_account_id' => ["{$account->name} is kept in " . ($account->currency ?? $this->current->get()->base_currency) . ", not {$data['currency']}."]]);
        }

        $userId = $request->user()->getKey();
        $draft = $save->handle($data, null, $userId, newType: 'expense');

        return $this->present($approve->handle($draft, $userId)->load(['lines', 'contact:id,name,tax_id', 'paidFrom:id,code,name']), 201);
    }

    /** Void a recorded expense: its entry is reversed, the number is kept, the reason is audited. */
    public function void(Request $request, Document $expense, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->present($workflow->void($expense, $reason, $request->user()->getKey(), $this->current->membership()));
    }
}
