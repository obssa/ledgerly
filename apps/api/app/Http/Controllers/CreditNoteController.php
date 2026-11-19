<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Money;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 2, credit notes: a return or price correction of one posted invoice. It is drafted here and then approved
 * like an invoice (POST /invoices/{id}/approve), which posts it, registers it with the tax authority where that
 * applies, and applies it to the invoice while that still owes. Credit left over can be applied to another open
 * invoice of the same customer.
 */
final class CreditNoteController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json(Document::query()->where('type', 'credit_note')
            ->with(['contact:id,name', 'original:id,number'])
            ->orderByDesc('issue_date')->orderBy('id')
            ->cursorPaginate(50));
    }

    /** A draft credit note for a posted invoice: its lines (the items returned or the price taken off) and the reason. */
    public function store(Request $request, Document $invoice, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        if ($invoice->type !== 'invoice') {
            throw new NotFoundHttpException();
        }

        return $this->credit($request, $invoice, $save);
    }

    /**
     * 082: a draft credit note for a cash sale, the way a registered one is corrected. It was paid on the spot, so the
     * credit stays as the customer's, to refund or apply to an invoice.
     */
    public function storeForCashSale(Request $request, Document $salesReceipt, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        if ($salesReceipt->type !== 'sales_receipt') {
            throw new NotFoundHttpException();
        }

        return $this->credit($request, $salesReceipt, $save);
    }

    private function credit(Request $request, Document $invoice, SaveInvoiceDraft $save): JsonResponse
    {
        if (! in_array($invoice->status, ['posted', 'part_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['invoice' => ["{$invoice->label()} is not posted: change the draft instead."]]);
        }

        $data = $request->validate([
            'issue_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $invoice->issue_date->format('Y-m-d')],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],   // the reason, printed on the credit note
            'number' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.discount_rate' => ['nullable', 'decimal:0,6', 'between:0,1'],
            'lines.*.account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')],
            'lines.*.project_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'project')->where('is_active', true)],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('items', 'id')],   // the company's own (row-level security)
            'restock' => ['sometimes', 'boolean'],   // the goods came back into stock (058)
        ], ['notes.required' => 'Say why: the reason is printed on the credit note.']);

        $creditNote = $save->handle([...$data, 'prices_include_tax' => (bool) $invoice->prices_include_tax], null, $request->user()->getKey(), $invoice);

        return response()->json($creditNote->load('lines'), 201);
    }

    /** Applies credit left on a posted credit note to another open invoice of the same customer and currency. */
    public function apply(Request $request, Document $creditNote, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('payments.receive');
        if ($creditNote->type !== 'credit_note') {
            throw new NotFoundHttpException();
        }
        $data = $request->validate([
            'invoice_id' => ['required', 'uuid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
        ]);

        return DB::transaction(function () use ($data, $creditNote, $current, $request) {
            $invoice = Document::query()->where('type', 'invoice')->find($data['invoice_id'])
                ?? throw ValidationException::withMessages(['invoice_id' => ['Not an invoice of this company.']]);
            $docs = Document::query()->whereIn('id', [$creditNote->getKey(), $invoice->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $cn = $docs[$creditNote->getKey()];
            $inv = $docs[$invoice->getKey()];
            $amount = Money::of($data['amount'], $cn->currency);

            if (! in_array($cn->status, ['posted', 'part_paid'], true)) {
                throw ValidationException::withMessages(['amount' => ['This credit note has no credit left to apply.']]);
            }
            if ($inv->contact_id !== $cn->contact_id || $inv->currency !== $cn->currency || ! in_array($inv->status, ['posted', 'part_paid'], true)) {
                throw ValidationException::withMessages(['invoice_id' => ['Choose an open invoice of the same customer, in the same currency.']]);
            }
            if (Money::of((string) $cn->amount_due, $cn->currency)->minus($amount)->isNegative() || Money::of((string) $inv->amount_due, $cn->currency)->minus($amount)->isNegative()) {
                throw ValidationException::withMessages(['amount' => ["At most {$cn->amount_due} of credit is left, and {$inv->number} owes {$inv->amount_due}."]]);
            }

            DB::table('credit_applications')->insert([
                'id' => (string) Str::uuid7(), 'company_id' => $current->id(), 'credit_note_id' => $cn->getKey(),
                'invoice_id' => $inv->getKey(), 'amount' => $amount->toDecimalString(), 'created_by' => $request->user()->getKey(),
            ]);
            $this->audit->record($current->id(), 'credit_applied', 'credit_note', $cn->getKey(), null,
                ['invoice' => $inv->number, 'amount' => $amount->toDecimalString()], userId: $request->user()->getKey());

            return response()->json(['credit_note' => $cn->fresh(), 'invoice' => $inv->fresh()]);
        });
    }
}
