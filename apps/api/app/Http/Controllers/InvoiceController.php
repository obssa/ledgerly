<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\EditPostedInvoice;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Requests\SaveInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        $invoices = Document::query()
            ->where('type', 'invoice')
            ->with('contact:id,name')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('amount_due', '>', 0)->whereDate('due_date', '<', now()))
            ->orderByDesc('issue_date')
            ->cursorPaginate(50);

        return response()->json($invoices);
    }

    public function show(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json($invoice->load(['lines', 'contact', 'einvoiceSubmissions']));
    }

    public function store(SaveInvoiceRequest $request, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return response()->json($save->handle($request->validated(), null, $request->user()->getKey()), 201);
    }

    /** Drafts are saved directly; posted invoices go through EditPostedInvoice, which keeps their history. */
    public function update(SaveInvoiceRequest $request, Document $invoice, SaveInvoiceDraft $save, EditPostedInvoice $edit): JsonResponse
    {
        if ($invoice->status !== 'posted') {
            $this->authorizeCompany('invoices.create');

            return response()->json($save->handle($request->validated(), $invoice, $request->user()->getKey()));
        }

        $this->authorizeCompany('transactions.edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json($edit->handle(
            $invoice, $request->validated(), $reason, $request->user()->getKey(), app(CurrentCompany::class)->membership(),
        ));
    }

    /** Every saved version and every audit record for this document, newest first. */
    public function history(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json([
            'versions' => DocumentVersion::query()->where('document_id', $invoice->getKey())->orderByDesc('version')->get(),
            'audit' => DB::table('audit_log')
                ->where('subject_id', $invoice->getKey())
                ->orderByDesc('id')
                ->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']),
        ]);
    }

    public function approve(Request $request, Document $invoice, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');

        return response()->json($approve->handle($invoice, $request->user()->getKey()));
    }
}
