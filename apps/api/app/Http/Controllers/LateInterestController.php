<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\LateInterest;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 2: interest on late payment (079). Seeing what would be charged takes invoices.view; charging it makes a draft
 * invoice, so it takes invoices.create (posting it takes invoices.approve, as for any invoice). The invoice is loaded
 * through the company-scoped binding.
 */
final class LateInterestController extends Controller
{
    public function __construct(private readonly LateInterest $interest, private readonly CurrentCompany $current)
    {
    }

    public function show(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $data = $request->validate(['through' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json([...$this->interest->preview($invoice, $data['through'] ?? $this->current->today()),
            'can' => ['charge' => (bool) $this->current->membership()?->can('invoices.create')]]);
    }

    public function store(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate(['through' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->interest->charge($invoice, $data['through'] ?? $this->current->today(), $request->user()->getKey()), 201);
    }
}
