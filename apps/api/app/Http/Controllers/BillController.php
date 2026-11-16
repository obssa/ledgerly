<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Purchases\DuplicateBills;
use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\EditPostedInvoice;
use App\Domain\Sales\Actions\InvoiceWorkflow;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Requests\SaveBillRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 3, bills: what suppliers invoice the company. Saved as a draft, optionally sent for approval, then posted
 * to accounts payable (Dr cost and input VAT, Cr payable) with the company's own gapless number; the supplier's
 * invoice number is kept as the reference. Posted bills are edited with history, or voided while unpaid.
 * Shares the document machinery with invoices; only the permissions (bills.*) and the accounts differ.
 */
final class BillController extends Controller
{
    public function __construct(private readonly DuplicateBills $duplicates, private readonly CurrentCompany $current)
    {
    }

    private function present(Document $bill, int $status = 200): JsonResponse
    {
        $membership = $this->current->membership();

        return response()->json([
            ...$bill->toArray(),
            'warnings' => $bill->type === 'bill' ? $this->duplicates->warnings($bill) : [],
            'can' => [
                'edit' => (bool) $membership?->can($bill->isPosted() ? 'transactions.edit' : 'bills.create'),
                'approve' => (bool) $membership?->can('bills.approve'),
                'void' => (bool) $membership?->can('transactions.edit'),
                'credit' => (bool) $membership?->can('bills.create'),
                'apply' => (bool) $membership?->can('payments.pay'),
                'refund' => (bool) $membership?->can('payments.receive'),   // a supplier paying credit back
            ],
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,awaiting_approval,posted,part_paid,paid,void'],
            'contact_id' => ['nullable', 'uuid'],
            'open' => ['nullable', 'boolean'],   // posted and not fully paid: what a payment can settle
        ]);

        $bills = Document::query()
            ->where('type', 'bill')
            ->with('contact:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->when($filters['open'] ?? false, fn ($q) => $q->whereIn('status', ['posted', 'part_paid']))
            // Open bills by due date (the order they are paid in); otherwise newest first.
            ->when($filters['open'] ?? false,
                fn ($q) => $q->orderByRaw('due_date asc nulls last')->orderBy('issue_date'),
                fn ($q) => $q->orderByDesc('issue_date'))
            ->orderBy('id')
            ->cursorPaginate(50);

        return response()->json($bills);
    }

    public function show(Document $bill): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return $this->present($bill->load(['lines', 'contact:id,name,tax_id,email,payout', 'original:id,number,reference']));
    }

    public function store(SaveBillRequest $request, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('bills.create');

        return $this->present($save->handle($request->validated(), null, $request->user()->getKey(), newType: 'bill'), 201);
    }

    /** Drafts are saved directly; posted bills go through EditPostedInvoice, which keeps their history. */
    public function update(SaveBillRequest $request, Document $bill, SaveInvoiceDraft $save, EditPostedInvoice $edit): JsonResponse
    {
        if (! $bill->isPosted()) {
            $this->authorizeCompany('bills.create');

            return $this->present($save->handle($request->validated(), $bill, $request->user()->getKey()));
        }

        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->present($edit->handle($bill, $request->validated(), $reason, $request->user()->getKey(), $this->current->membership()));
    }

    public function submit(Request $request, Document $bill, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('bills.create');

        return $this->present($workflow->submitForApproval($bill, $request->user()->getKey()));
    }

    public function reject(Request $request, Document $bill, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('bills.approve');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->present($workflow->reject($bill, $reason, $request->user()->getKey()));
    }

    public function approve(Request $request, Document $bill, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('bills.approve');
        $version = $request->validate(['lock_version' => ['nullable', 'integer', 'min:1']])['lock_version'] ?? null;

        return $this->present($approve->handle($bill, $request->user()->getKey(), $version === null ? null : (int) $version));
    }

    /** Void a posted, unpaid bill: its entry is reversed, the number is kept, the reason is audited. */
    public function void(Request $request, Document $bill, InvoiceWorkflow $workflow): JsonResponse
    {
        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
        if ($bill->type === 'vendor_credit' && $bill->isPosted()) {
            // It may already be applied to bills: a posted supplier credit stays, like a credit note.
            throw ValidationException::withMessages(['reason' => ['A posted supplier credit is not voided. Enter a bill for the difference instead.']]);
        }

        return $this->present($workflow->void($bill, $reason, $request->user()->getKey(), $this->current->membership()));
    }

    /** Every saved version and every audit record for this bill, newest first. */
    public function history(Document $bill): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return response()->json([
            'versions' => DocumentVersion::query()->where('document_id', $bill->getKey())->orderByDesc('version')->get(),
            'audit' => DB::table('audit_log')
                ->where('company_id', $bill->company_id)
                ->where('subject_id', $bill->getKey())
                ->orderByDesc('id')
                ->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']),
        ]);
    }
}
