<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\Currency;
use App\Domain\Ledger\Money;
use App\Domain\Purchases\Actions\PayBills;
use App\Domain\Purchases\WithholdingAdvice;
use App\Domain\Sales\Actions\ReversePayment;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 3, payments made: paying one or more of a supplier's bills from a bank, cash or mobile-money account, with any
 * tax withheld (on a certificate given to the supplier) and any fee the bank took. Money leaves the company, so only
 * accountants, owners and admins pay (payments.pay). Recorded once; a payment that did not go through is reversed.
 */
final class PaymentMadeController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly WithholdingAdvice $advice)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Payment::query()->where('direction', 'out')
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->with('contact:id,name')->withCount('allocations')
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Payment $paymentMade): JsonResponse
    {
        $this->authorizeCompany('payments.view');

        return response()->json([
            ...$paymentMade->load(['contact:id,name,tax_id,payout', 'allocations.document:id,number,reference,issue_date,total,amount_due,status'])->toArray(),
            'can' => ['reverse' => (bool) $this->current->membership()?->can('payments.reverse')],
        ]);
    }

    /** What the country's rules say about withholding on a payment to this supplier. */
    public function withholding(Contact $supplier): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        if (! $supplier->is_vendor) {
            throw new NotFoundHttpException();
        }

        return response()->json([...$this->advice->for($this->current->get(), $supplier), 'cash_limit' => $this->advice->cashLimit($this->current->get())]);
    }

    public function store(Request $request, PayBills $pay): JsonResponse
    {
        $this->authorizeCompany('payments.pay');

        $data = $request->validate([
            'contact_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'method' => ['required', Rule::in(PaymentReceivedController::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding_certificate' => ['nullable', 'string', 'max:60', 'required_unless:withholding,null,0,0.0,0.00'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.bill_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], [
            'allocations.*.bill_id.distinct' => 'Each bill appears once.',
            'withholding_certificate.required_unless' => 'Give the number of the withholding receipt handed to the supplier.',
        ]);

        $payment = $pay->handle($data, $request->user()->getKey());

        return response()->json([...$payment->toArray(), 'warnings' => $this->warnings($payment)], 201);
    }

    public function reverse(Request $request, Payment $paymentMade, ReversePayment $reverse): JsonResponse
    {
        $this->authorizeCompany('payments.reverse');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($reverse->handle($paymentMade, $data['date'], $data['reason'], $request->user()->getKey()));
    }

    /** Warnings that do not block: a cash payment above the country's limit. @return list<array{type: string, message: string}> */
    private function warnings(Payment $payment): array
    {
        $company = $this->current->get();
        $limit = $this->advice->cashLimit($company);
        if ($payment->method !== 'cash' || $limit === null) {
            return [];
        }
        $base = Money::of((string) $payment->amount, $payment->currency)->convertTo($company->base_currency, (string) $payment->fx_rate);
        if (Money::of($limit, $company->base_currency)->minus($base)->isNegative()) {
            return [['type' => 'cash_limit', 'message' => "Cash payments above {$company->base_currency} {$limit} must go through a bank or an authorised electronic channel."]];
        }

        return [];
    }
}
