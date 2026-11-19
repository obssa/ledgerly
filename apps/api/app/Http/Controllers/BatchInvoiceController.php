<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\BatchInvoices;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: batch invoices. Making them takes invoices.create; posting them as they are made, invoices.approve too.
 * The source invoice is loaded through the company-scoped binding, and each customer through the company-scoped model.
 */
final class BatchInvoiceController extends Controller
{
    public function __construct(private readonly BatchInvoices $batches, private readonly CurrentCompany $current)
    {
    }

    public function store(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:' . BatchInvoices::MAX],
            'contact_ids.*' => ['required', 'uuid', 'distinct'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'post' => ['boolean'],
        ]);
        $post = (bool) ($data['post'] ?? false);
        if ($post && ! $this->current->membership()?->can('invoices.approve')) {
            throw ValidationException::withMessages(['post' => ['Your role cannot post invoices: make drafts for someone who can to approve.']]);
        }

        $made = $this->batches->make($invoice, array_values($data['contact_ids']), $data['issue_date'] ?? $this->current->today(), $post, $request->user()->getKey());

        return response()->json(['data' => $made], 201);
    }
}
