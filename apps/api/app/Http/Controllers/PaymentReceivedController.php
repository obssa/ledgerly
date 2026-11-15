<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Sales\Actions\ReceivePayment;
use App\Domain\Sales\Actions\ReversePayment;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 2, payments received: cash, bank transfer, cheque, card or mobile money from a customer, allocated to one or
 * more of their invoices, with any tax the customer withheld and any fee the bank kept. Recorded once; a bounced or
 * returned payment is reversed with a reason.
 */
final class PaymentReceivedController extends Controller
{
    public const METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'mobile_money', 'other'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Payment::query()->where('direction', 'in')
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->with('contact:id,name')->withCount('allocations')
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $this->assertReceived($payment);

        return response()->json($payment->load(['contact:id,name', 'allocations.document:id,number,issue_date,total,amount_due,status']));
    }

    public function store(Request $request, ReceivePayment $receive): JsonResponse
    {
        $this->authorizeCompany('payments.receive');

        $data = $request->validate([
            'contact_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'method' => ['required', Rule::in(self::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'provider_txn_id' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding' => ['nullable', 'decimal:0,2', 'min:0'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], ['allocations.*.invoice_id.distinct' => 'Each invoice appears once.']);

        return response()->json($receive->handle($data, $request->user()->getKey()), 201);
    }

    public function reverse(Request $request, Payment $payment, ReversePayment $reverse): JsonResponse
    {
        $this->authorizeCompany('payments.reverse');
        $this->assertReceived($payment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($reverse->handle($payment, $data['date'], $data['reason'], $request->user()->getKey()));
    }

    private function assertReceived(Payment $payment): void
    {
        if ($payment->direction !== 'in') {
            throw new NotFoundHttpException();
        }
    }
}
