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

        return response()->json([
            ...$invoice->toArray(),
            'warnings' => $invoice->type !== 'invoice' || in_array($invoice->status, ['void', 'paid'], true) ? [] : $this->credit->warnings($this->current->get(), $invoice),
            'can' => [
                'edit' => (bool) $membership?->can($invoice->isPosted() ? 'transactions.edit' : 'invoices.create'),
                'approve' => (bool) $membership?->can('invoices.approve'),
                'void' => (bool) $membership?->can('transactions.edit'),
                'credit' => (bool) $membership?->can('invoices.create'),
                'apply' => (bool) $membership?->can('payments.receive'),
                'refund' => (bool) $membership?->can('payments.pay'),   // paying a customer's credit back
            ],
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
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
            // Open invoices oldest first (the order a payment settles them in); otherwise newest first.
            ->orderBy('issue_date', ($filters['open'] ?? false) ? 'asc' : 'desc')->orderBy('id')
            ->cursorPaginate(50);

        return response()->json($invoices);
    }

    public function show(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return $this->present($invoice->load(['lines', 'contact:id,name,tax_id,email', 'einvoiceSubmissions', 'original:id,number']));
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
    public function history(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json([
            'versions' => DocumentVersion::query()->where('document_id', $invoice->getKey())->orderByDesc('version')->get(),
            // Always name the company, even though row-level security would filter it anyway.
            'audit' => DB::table('audit_log')
                ->where('company_id', $invoice->company_id)
                ->where('subject_id', $invoice->getKey())
                ->orderByDesc('id')
                ->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']),
        ]);
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
    public function void(Request $request, Document $invoice, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json($workflow->void($invoice, $reason, $request->user()->getKey(), app(CurrentCompany::class)->membership()));
    }
}
