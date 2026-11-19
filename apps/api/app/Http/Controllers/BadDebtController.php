<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\BadDebts;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: bad debts (078). Writing off what an invoice still owes, and reversing a write-off when the customer pays
 * after all, post to the ledger, so they take invoices.approve; seeing them invoices.view. Invoices are loaded through
 * the company-scoped binding; a write-off of another company is simply not found.
 */
final class BadDebtController extends Controller
{
    public function __construct(private readonly BadDebts $badDebts, private readonly CurrentCompany $current)
    {
    }

    public function index(Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json(['data' => $this->badDebts->forInvoice((string) $invoice->getKey()),
            'can' => ['write_off' => (bool) $this->current->membership()?->can('invoices.approve')]]);
    }

    public function store(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');
        $data = $request->validate([
            'amount' => ['nullable', 'decimal:0,2', 'gt:0'],   // none: all it still owes
            'date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($this->badDebts->writeOff($invoice, isset($data['amount']) ? (string) $data['amount'] : null,
            $data['date'] ?? $this->current->today(), $data['reason'], $request->user()->getKey()), 201);
    }

    /** The customer paid after all: the write-off named (or the invoice's latest standing one) is reversed. */
    public function reverse(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.approve');
        $data = $request->validate([
            'write_off_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        // Only this invoice's own write-offs (and, under row-level security, only the company's).
        $standing = collect($this->badDebts->forInvoice((string) $invoice->getKey()))->filter(fn (array $w) => $w['reversed_on'] === null);
        $w = isset($data['write_off_id']) ? $standing->firstWhere('id', $data['write_off_id']) : $standing->first();
        if ($w === null) {
            throw ValidationException::withMessages(['write_off_id' => ["{$invoice->label()} has no write-off standing to reverse."]]);
        }

        return response()->json($this->badDebts->reverse((string) $w['id'], $data['date'] ?? $this->current->today(), $data['reason'] ?? null, $request->user()->getKey()));
    }
}
