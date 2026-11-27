<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\EditPostedInvoice;
use App\Domain\Sales\Actions\InvoiceWorkflow;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\CreditCheck;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Requests\SaveInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InvoiceController extends Controller
{
    use Concerns\FiltersByDate;

    public function __construct(private readonly CreditCheck $credit, private readonly CurrentCompany $current)
    {
    }

    /**
     * An invoice as the screens use it: the document, warnings that do not block (credit limit), and what the
     * signed-in member may do with it (the API checks again on every call).
     */
    private function present(Document $invoice, int $status = 200): JsonResponse
    {
        $membership = $this->current->membership();
        // Worked out per call: the route keeps one controller across requests, so nothing about a document is kept on it.
        $blocked = $invoice->isPosted() ? app(EditPostedInvoice::class)->blockedReason($invoice) : null;

        return response()->json([
            ...$invoice->toArray(),
            // 109: the term it is on, by name (a term turned off still reads back).
            'payment_terms' => $invoice->payment_terms_id ? \App\Domain\Sales\Models\PaymentTerm::query()->find($invoice->payment_terms_id, ['id', 'name'])?->only(['id', 'name']) : null,
            // The salesperson's name only (044): read directly, not as a User model with its sign-in fields.
            'salesperson' => $invoice->salesperson_id ? DB::table('users')->where('id', $invoice->salesperson_id)->first(['id', 'name']) : null,
            // The quote or sales order it was made from (056), through the company-scoped model.
            'source' => $invoice->source_document_id ? Document::query()->find($invoice->source_document_id, ['id', 'type', 'number'])?->only(['id', 'type', 'number']) : null,
            'warnings' => $invoice->type !== 'invoice' || in_array($invoice->status, ['void', 'paid'], true) ? [] : $this->credit->warnings($this->current->get(), $invoice),
            'can' => [
                'edit' => (bool) $membership?->can($invoice->isPosted() ? 'transactions.edit' : 'invoices.create'),
                // A posted document corrected in place (EditPostedInvoice): allowed for this member, and nothing in the way.
                'correct' => $invoice->isPosted() && (bool) $membership?->can('transactions.edit') && $blocked === null,
                'approve' => (bool) $membership?->can('invoices.approve'),
                'void' => (bool) $membership?->can('transactions.edit'),
                'credit' => (bool) $membership?->can('invoices.create'),
                'apply' => (bool) $membership?->can('payments.receive'),
                'refund' => (bool) $membership?->can('payments.pay'),   // paying a customer's credit back
                'remind' => (bool) $membership?->can('invoices.create'),
                'email' => (bool) $membership?->can('invoices.create'),
                'retain' => (bool) $membership?->can('payments.receive'),
            ],
            // Why it cannot be corrected in place (a payment, stock, the tax authority…); null when it can.
            'correct_blocked' => $blocked,
            'retentions' => $invoice->type === 'invoice' ? app(\App\Domain\Sales\Retention::class)->of($invoice->getKey()) : [],
            'reminders' => $invoice->type === 'invoice' ? app(\App\Domain\Sales\InvoiceReminders::class)->sentFor($invoice->getKey()) : [],
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $range = $this->dateRange($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,awaiting_approval,posted,part_paid,paid,void'],
            'contact_id' => ['nullable', 'uuid'],
            'open' => ['nullable', 'boolean'],   // posted and not fully paid: what a payment can settle
        ]);

        $invoices = Document::query()
            ->where('type', 'invoice')
            ->with('contact:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->when($filters['open'] ?? false, fn ($q) => $q->whereIn('status', ['posted', 'part_paid']))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('amount_due', '>', 0)->whereDate('due_date', '<', now()))
            ->when(true, fn ($q) => $this->withinDates($q, 'issue_date', $range))   // the period asked for (FiltersByDate)
            // Open invoices oldest first (the order a payment settles them in); otherwise newest first.
            ->orderBy('issue_date', ($filters['open'] ?? false) ? 'asc' : 'desc')->orderBy('id')
            ->cursorPaginate(50);

        return response()->json($invoices);
    }

    public function show(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return $this->present($invoice->load(['lines', 'contact:id,name,tax_id,email,phone', 'einvoiceSubmissions', 'original:id,number']));
    }

    public function store(SaveInvoiceRequest $request, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return $this->present($save->handle($request->validated(), null, $request->user()->getKey()), 201);
    }

    /** Drafts are saved directly; posted invoices go through EditPostedInvoice, which keeps their history. */
    public function update(SaveInvoiceRequest $request, Document $invoice, SaveInvoiceDraft $save, EditPostedInvoice $edit): JsonResponse
    {
        if (! $invoice->isPosted()) {
            $this->authorizeCompany('invoices.create');

            return $this->present($save->handle($request->validated(), $invoice, $request->user()->getKey()));
        }

        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->present($edit->handle(
            $invoice, $request->validated(), $reason, $request->user()->getKey(), app(CurrentCompany::class)->membership(),
        ));
    }

    /** Every saved version and every audit record for this document, newest first. */
    public function history(Document $invoice, \App\Domain\Audit\DocumentHistory $history): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json($history->for($invoice));
    }

    public function submit(Request $request, Document $invoice, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return response()->json($workflow->submitForApproval($invoice, $request->user()->getKey()));
    }

    public function reject(Request $request, Document $invoice, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json($workflow->reject($invoice, $reason, $request->user()->getKey()));
    }

    public function approve(Request $request, Document $invoice, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');
        $version = $request->validate(['lock_version' => ['nullable', 'integer', 'min:1']])['lock_version'] ?? null;

        return $this->present($approve->handle($invoice, $request->user()->getKey(), $version === null ? null : (int) $version));
    }

    /** Void a posted, unpaid invoice: its entry is reversed, the number is kept, the reason is audited. */
    /** Module 9a: retention the customer holds back on this invoice (050): an amount, or a share of the invoice total. */
    public function retain(Request $request, Document $invoice, \App\Domain\Sales\Retention $retention): JsonResponse
    {
        $this->authorizeCompany('payments.receive');
        abort_unless($invoice->type === 'invoice', 404);
        $data = $request->validate([
            'amount' => ['required_without:percent', 'nullable', 'decimal:0,2', 'gt:0'],
            'percent' => ['required_without:amount', 'nullable', 'decimal:0,2', 'gt:0', 'max:100'],
            'held_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
        ]);
        // A share of the invoice total, rounded half up to the cent.
        $amount = $data['amount'] ?? bcadd(bcdiv(bcmul((string) $invoice->total, (string) $data['percent'], 6), '100', 6), '0.005', 2);
        if (bccomp($amount, (string) $invoice->amount_due, 2) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => ["The invoice still owes {$invoice->amount_due}: retention cannot be more."]]);
        }
        $retention->hold($this->current->get(), $invoice, $amount, $data['held_on'], $request->user()->getKey());

        return $this->present($invoice->refresh(), 201);
    }

    /** Releases the retention held on this invoice: the customer owes it again. */
    public function releaseRetention(Request $request, Document $invoice, \App\Domain\Sales\Retention $retention): JsonResponse
    {
        $this->authorizeCompany('payments.receive');
        abort_unless($invoice->type === 'invoice', 404);
        $data = $request->validate(['released_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()]]);
        $retention->release($this->current->get(), $invoice, $data['released_on'], $request->user()->getKey());

        return $this->present($invoice->refresh());
    }

    /**
     * Module 2: the posted invoice (or credit note) sent to the customer by email, its lines and totals in the message.
     * At most once in five minutes for the same document; recorded in the audit log.
     */
    public function email(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        abort_unless(in_array($invoice->type, ['invoice', 'credit_note'], true), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        if (! in_array($invoice->status, ['posted', 'part_paid', 'paid'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice' => ['Only a posted document is sent: post it first.']]);
        }
        $company = $this->current->get();
        $contact = DB::table('contacts')->where(['company_id' => $company->getKey(), 'id' => $invoice->contact_id])->first(['name', 'email']);
        $to = trim((string) ($contact->email ?? ''));
        if ($to === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice' => ['The customer has no email address: add one to send the invoice.']]);
        }
        $key = 'invoice-email:' . $invoice->getKey();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 1)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice' => ['It was sent a moment ago: wait a few minutes before sending it again.']]);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 300);

        $invoice->load(['lines', 'einvoiceSubmissions']);
        $money = fn ($v) => number_format((float) $v, 2);
        $clearance = $invoice->einvoiceSubmissions->firstWhere('status', 'cleared')?->clearance_number;
        \Illuminate\Support\Facades\Notification::route('mail', [$to => (string) $contact->name])->notify(new \App\Notifications\InvoiceSent(
            company: (string) ($company->trading_name ?: $company->legal_name), companyTin: $company->tin ? (string) $company->tin : null,
            replyTo: $company->email ? (string) $company->email : null, customer: (string) $contact->name, number: (string) $invoice->number,
            kind: $invoice->type, issueDate: (string) \App\Support\DateFormat::format($invoice->issue_date, $company), dueDate: \App\Support\DateFormat::format($invoice->due_date, $company), currency: (string) $invoice->currency,
            lines: $invoice->lines->map(fn ($l) => ['description' => (string) $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'),
                'unit_price' => $money($l->unit_price), 'amount' => $money(bcadd((string) $l->line_subtotal, (string) $l->line_tax, 2))])->values()->all(),
            subtotal: $money($invoice->subtotal), tax: $money($invoice->tax_total), total: $money($invoice->total), amountDue: $money($invoice->amount_due),
            clearance: $clearance ? (string) $clearance : null, note: $data['note'] ?? null,
            pdf: app(\App\Domain\Sales\InvoicePdf::class)->render($company, $invoice),   // the invoice itself, attached
        ));
        app(\App\Domain\Audit\AuditLogger::class)->record($company->getKey(), 'invoice_emailed', $invoice->type, $invoice->getKey(), null,
            ['sent_to' => $to, 'number' => $invoice->number], userId: $request->user()->getKey());

        return response()->json(['sent_to' => $to]);
    }

    /** Module 2: a posted invoice or credit note as a PDF file, as printed (InvoicePdf). */
    public function pdf(Document $invoice, \App\Domain\Sales\InvoicePdf $pdf): \Illuminate\Http\Response
    {
        $this->authorizeCompany('invoices.view');
        if (! in_array($invoice->status, ['posted', 'part_paid', 'paid'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invoice' => ['Only a posted document has a PDF: post it first.']]);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number) . '.pdf';

        return response($pdf->render($this->current->get(), $invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Module 2: a payment reminder by email to the customer, sent by hand (048). */
    public function remind(Request $request, Document $invoice, \App\Domain\Sales\InvoiceReminders $reminders): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        abort_unless($invoice->type === 'invoice', 404);
        $reminders->send($this->current->get(), $invoice, 'manual', null, $request->user()->getKey());

        return $this->present($invoice->refresh());
    }

    public function void(Request $request, Document $invoice, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json($workflow->void($invoice, $reason, $request->user()->getKey(), app(CurrentCompany::class)->membership()));
    }
}
