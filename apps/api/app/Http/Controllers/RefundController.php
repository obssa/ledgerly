<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Sales\Actions\RecordRefund;
use App\Domain\Sales\Actions\ReversePayment;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Refunds (database/schema/023_refunds.sql): money paid back to a customer for credit they hold, and money a supplier
 * pays back for credit the company holds. Paying a customer back is money leaving the company (payments.pay); a refund
 * from a supplier is money arriving (payments.receive). Recorded once; reversed with a reason.
 */
final class RefundController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('payments.view');

        return response()->json(Payment::query()->where('kind', 'refund')
            ->with(['contact:id,name', 'allocations.document:id,number'])
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Payment $refund): JsonResponse
    {
        $this->authorizeCompany('payments.view');

        return response()->json([
            ...$refund->load(['contact:id,name', 'allocations.document:id,number,type,total,amount_due,status'])->toArray(),
            'can' => ['reverse' => (bool) $this->current->membership()?->can('payments.reverse')],
        ]);
    }

    /** Pays a customer back: from a credit note's credit left (credit_id) or the unused part of their payment (payment_id). */
    public function storeCustomer(Request $request, RecordRefund $refund): JsonResponse
    {
        $this->authorizeCompany('payments.pay');

        return response()->json($refund->handle('customer', $this->validated($request), $request->user()->getKey()), 201);
    }

    /** A supplier pays back: a supplier credit's credit left (credit_id) or the unused part of an advance (payment_id). */
    public function storeSupplier(Request $request, RecordRefund $refund): JsonResponse
    {
        $this->authorizeCompany('payments.receive');

        return response()->json($refund->handle('supplier', $this->validated($request), $request->user()->getKey()), 201);
    }

    public function reverse(Request $request, Payment $refund, ReversePayment $reverse): JsonResponse
    {
        $this->authorizeCompany('payments.reverse');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($reverse->handle($refund, $data['date'], $data['reason'], $request->user()->getKey()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'contact_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'method' => ['required', Rule::in(PaymentReceivedController::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'credit_id' => ['nullable', 'uuid', 'required_without:payment_id', 'prohibits:payment_id'],
            'payment_id' => ['nullable', 'uuid', 'required_without:credit_id'],
        ], [
            'credit_id.required_without' => 'Say what is refunded: a credit, or the unused part of a payment.',
            'credit_id.prohibits' => 'Refund a credit or a payment, not both at once.',
        ]);
    }
}
