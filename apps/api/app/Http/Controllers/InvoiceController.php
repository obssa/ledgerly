<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Actions\ApproveInvoice;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Sales\Models\Document;
use App\Http\Requests\SaveInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(SaveInvoiceRequest $request, Document $invoice, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return response()->json($save->handle($request->validated(), $invoice, $request->user()->getKey()));
    }

    public function approve(Request $request, Document $invoice, ApproveInvoice $approve): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');

        return response()->json($approve->handle($invoice, $request->user()->getKey()));
    }
}
