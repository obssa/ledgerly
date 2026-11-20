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
 * Module 2, sales receipts (070): the cash sale, paid at once by cash, card, transfer or wallet. Recorded and posted in
 * one step (Dr the money account it went into, Cr income and tax payable; stock items leave at their cost), with the
 * company's own number (SR-). It is a sale and money received in one, so recording it takes both invoices.create and
 * payments.receive. A mistake is voided (the entry is reversed, the goods come back) and entered again.
 */
final class SalesReceiptController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    private function present(Document $receipt, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$receipt->toArray(),
            'can' => ['void' => $receipt->status !== 'void' && (bool) $this->current->membership()?->can('transactions.edit'),
                // 082: a registered cash sale is corrected with a credit note.
                'credit' => in_array($receipt->status, ['posted', 'paid'], true) && (bool) $this->current->membership()?->can('invoices.create')],
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Document::query()->where('type', 'sales_receipt')
            ->with(['contact:id,name', 'paidFrom:id,code,name'])
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->orderByDesc('issue_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Document $salesReceipt): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return $this->present($salesReceipt->load(['lines', 'contact:id,name,tax_id,email', 'paidFrom:id,code,name', 'einvoiceSubmissions']));
    }

    /** Saves and posts at once: the money has already been received. */
    public function store(Request $request, SaveInvoiceDraft $save, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $this->authorizeCompany('payments.receive');
        $data = $request->validate([
            'contact_id' => ['required', 'uuid', Rule::exists('contacts', 'id')->where('is_customer', true)],
            'paid_from_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')
                ->where(fn (Builder $q) => $q->where('is_active', true)->whereIn('subtype', ['bank', 'cash', 'mobile_money']))],
            'payment_method' => ['required', Rule::in(PaymentReceivedController::METHODS)],
            'reference' => ['nullable', 'string', 'max:60'],   // a till slip or transaction number
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'prices_include_tax' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('is_active', true)],   // for stock lines
            'salesperson_id' => ['nullable', 'uuid'],   // an active member of the company: checked in SaveInvoiceDraft (044)
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('is_active', true)->where('type', 'income'))],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->whereIn('kind', ['vat', 'tot'])],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')->where('is_active', true)],
            'lines.*.unit_id' => ['nullable', 'uuid', Rule::exists('item_units', 'id')->where('is_active', true)],   // 072
            'lines.*.project_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'project')->where('is_active', true)],
            'lines.*.batch_number' => ['nullable', 'string', 'max:2000'],   // 096: the serial numbers (or batch) sold; empty takes the oldest
        ], ['lines.*.account_id.exists' => 'Choose an income account.']);

        // Loaded through the company-scoped model (CLAUDE.md rule 4); the account's currency is the sale's.
        $account = Account::query()->findOrFail($data['paid_from_account_id']);
        if (($account->currency ?? $this->current->get()->base_currency) !== $data['currency']) {
            throw ValidationException::withMessages(['paid_from_account_id' => ["{$account->name} is kept in " . ($account->currency ?? $this->current->get()->base_currency) . ", not {$data['currency']}."]]);
        }

        $userId = $request->user()->getKey();
        $draft = $save->handle($data, null, $userId, newType: 'sales_receipt');

        return $this->present($approve->handle($draft, $userId)->load(['lines', 'contact:id,name,tax_id,email', 'paidFrom:id,code,name']), 201);
    }

    /** Void a cash sale: its entry is reversed, its goods come back, the number is kept, the reason is audited. */
    public function void(Request $request, Document $salesReceipt, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->present($workflow->void($salesReceipt, $reason, $request->user()->getKey(), $this->current->membership()));
    }
}
