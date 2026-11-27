<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Currency;
use App\Domain\Purchases\ScheduledPayments;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 3: scheduled supplier payments (103). A payment planned for a later day, for some of a supplier's bills. It
 * is not money: on the day, the people who pay are reminded, and someone confirms that the money went out, which
 * records the payment made. Planning, changing, confirming and cancelling take payments.pay; seeing them payments.view.
 */
final class ScheduledPaymentController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly ScheduledPayments $plans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $q = $request->validate(['status' => ['nullable', Rule::in(['scheduled', 'paid', 'cancelled'])], 'contact_id' => ['nullable', 'uuid']]);
        $rows = $this->plans->list($q['status'] ?? null, $q['contact_id'] ?? null);
        $open = array_values(array_filter($rows, fn ($r) => $r['status'] === 'scheduled'));
        $totals = [];
        foreach ($open as $r) {   // what is planned, by currency: all, and what is due today or earlier
            $t = $totals[$r['currency']] ?? ['currency' => $r['currency'], 'scheduled' => '0.00', 'due' => '0.00', 'count' => 0, 'due_count' => 0];
            $t['scheduled'] = bcadd($t['scheduled'], $r['amount'], 2);
            $t['count']++;
            if ($r['due']) {
                $t['due'] = bcadd($t['due'], $r['amount'], 2);
                $t['due_count']++;
            }
            $totals[$r['currency']] = $t;
        }
        ksort($totals);

        return response()->json(['data' => $rows, 'totals' => array_values($totals), 'today' => $this->current->today(),
            'can' => ['pay' => (bool) $this->current->membership()?->can('payments.pay')]]);
    }

    public function show(string $scheduledPayment): JsonResponse
    {
        $this->authorizeCompany('payments.view');

        return response()->json([...$this->plans->one($scheduledPayment), 'today' => $this->current->today(),
            'can' => ['pay' => (bool) $this->current->membership()?->can('payments.pay')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $data = $request->validate([
            'contact_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'currency' => ['required', Rule::in(Currency::all())],
            ...$this->planRules(),
        ], $this->messages());

        return response()->json($this->plans->schedule($data, $request->user()->getKey()), 201);
    }

    public function update(Request $request, string $scheduledPayment): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $this->plans->one($scheduledPayment);   // another company's (or no) scheduled payment: not found, before anything is checked
        $data = $request->validate(['account_id' => ['required', 'uuid'], ...$this->planRules()], $this->messages());

        return response()->json($this->plans->update($scheduledPayment, $data, $request->user()->getKey()));
    }

    /** The money went out: the payment made is recorded now, on the day it left (today unless given). */
    public function confirm(Request $request, string $scheduledPayment): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $this->plans->one($scheduledPayment);   // another company's (or no) scheduled payment: not found, before anything is checked
        $data = $request->validate([
            'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'reference' => ['nullable', 'string', 'max:100'],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            // Through an account in the company's currency: what it moved, in that currency (107).
            'account_amount' => ['nullable', 'decimal:0,2', 'gt:0'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding_certificate' => ['nullable', 'string', 'max:60', 'required_unless:withholding,null,0,0.0,0.00'],
            'cheque_number' => ChequeNumbers::RULE,
        ], [
            'payment_date.before_or_equal' => 'A payment is confirmed once the money went out: today or earlier.',
            'withholding_certificate.required_unless' => 'Give the number of the withholding receipt handed to the supplier.',
        ]);

        return response()->json($this->plans->confirm($scheduledPayment, [...$data, 'payment_date' => $data['payment_date'] ?? $this->current->today()],
            $request->user()->getKey()));
    }

    public function cancel(Request $request, string $scheduledPayment): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $this->plans->one($scheduledPayment);   // another company's (or no) scheduled payment: not found, before anything is checked
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];

        return response()->json($this->plans->cancel($scheduledPayment, $reason, $request->user()->getKey()));
    }

    /** @return array<string, list<mixed>> */
    private function planRules(): array
    {
        return [
            'pay_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $this->current->today()],
            'method' => ['required', Rule::in(PaymentReceivedController::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
            'bills' => ['required', 'array', 'min:1', 'max:' . ScheduledPayments::MAX_BILLS],
            'bills.*.bill_id' => ['required', 'uuid', 'distinct'],
            'bills.*.amount' => ['nullable', 'decimal:0,2', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'pay_on.after_or_equal' => 'Schedule it for today or a later day; a payment already made is recorded under Pay bills.',
            'bills.required' => 'Choose at least one bill to pay.',
            'bills.*.bill_id.distinct' => 'Each bill appears once.',
        ];
    }
}
