<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Currency;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Actions\AllocatePayment;
use App\Domain\Sales\Actions\ReceivePayment;
use App\Domain\Sales\Actions\ReversePayment;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 2, payments received: cash, bank transfer, cheque, card or mobile money from a customer, allocated to one or
 * more of their invoices, with any tax the customer withheld and any fee the bank kept. Recorded once; a bounced or
 * returned payment is reversed with a reason.
 */
final class PaymentReceivedController extends Controller
{
    use Concerns\FiltersByDate;

    public const METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'mobile_money', 'other'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $range = $this->dateRange($request);
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Payment::query()->where(['direction' => 'in', 'kind' => 'payment'])
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->with('contact:id,name')->withCount('allocations')
            ->when(true, fn ($q) => $this->withinDates($q, 'payment_date', $range))   // the period asked for (FiltersByDate)
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $this->assertReceived($payment);

        $payment->load(['contact:id,name', 'allocations.document:id,number,issue_date,total,amount_due,status']);

        return response()->json([...$payment->toArray(), 'unallocated' => $payment->unallocated(),
            'can' => ['allocate' => (bool) app(\App\Domain\Tenancy\Support\CurrentCompany::class)->membership()?->can('payments.receive'),
                'reverse' => (bool) app(\App\Domain\Tenancy\Support\CurrentCompany::class)->membership()?->can('payments.reverse'),
                'refund' => (bool) app(\App\Domain\Tenancy\Support\CurrentCompany::class)->membership()?->can('payments.pay')]]);
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
            'cheque_number' => ChequeNumbers::RULE,   // the payer's cheque, if it is one
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999999999'],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding' => ['nullable', 'decimal:0,2', 'min:0'],
            'withholding_certificate' => ['nullable', 'string', 'max:60'],   // the customer's certificate: it backs the credit against profit tax
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], ['allocations.*.invoice_id.distinct' => 'Each invoice appears once.']);

        return response()->json($receive->handle($data, $request->user()->getKey()), 201);
    }

    /** Applies what the payment did not allocate (the customer's credit) to their open invoices. */
    public function allocate(Request $request, Payment $payment, AllocatePayment $allocate): JsonResponse
    {
        $this->authorizeCompany('payments.receive');
        $this->assertReceived($payment);
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], ['allocations.*.invoice_id.distinct' => 'Each invoice appears once.']);

        return response()->json($allocate->handle($payment,
            array_map(fn (array $a) => ['document_id' => $a['invoice_id'], 'amount' => $a['amount']], $data['allocations']), $request->user()->getKey()));
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

    /**
     * A cheque received that bounced: the payment is reversed on the day the bank returned it (Dr accounts receivable,
     * Cr bank), so its invoices owe again; the bank's charge for it, if any, is booked to bank charges. The deposit and
     * the bounce are both on the bank statement, so a statement match or reconciliation of the deposit stands.
     */
    public function bounce(Request $request, Payment $payment, ReversePayment $reverse, PostingService $posting): JsonResponse
    {
        $this->authorizeCompany('payments.reverse');
        $this->assertReceived($payment);
        $current = app(CurrentCompany::class);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . substr((string) $payment->payment_date, 0, 10), 'before_or_equal:' . $current->today()],
            'fee' => ['nullable', 'decimal:0,2', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], ['date.after_or_equal' => 'A cheque bounces on or after the day it was received (' . substr((string) $payment->payment_date, 0, 10) . ').']);
        if ($payment->method !== 'cheque' || $payment->kind !== 'payment') {
            throw ValidationException::withMessages(['date' => ['Only a cheque received bounces: reverse other payments instead.']]);
        }
        if ($payment->status !== 'posted') {
            throw ValidationException::withMessages(['date' => ['This payment is already reversed.']]);
        }
        $company = $current->get();
        $account = Account::query()->findOrFail($payment->account_id);
        $fee = Money::of($data['fee'] ?? '0', $company->base_currency);
        if (! $fee->isZero() && $account->currency !== null && $account->currency !== $company->base_currency) {
            throw ValidationException::withMessages(['fee' => ["{$account->name} is kept in {$account->currency}: enter the bank's charge as a statement line instead."]]);
        }

        $userId = $request->user()->getKey();
        $reason = $data['reason'] ?? 'Cheque ' . ($payment->cheque_number ?? '') . ' bounced';
        $result = DB::transaction(function () use ($payment, $data, $reason, $userId, $reverse, $posting, $fee, $account, $company) {
            $result = $reverse->handle($payment, $data['date'], $reason, $userId, bounced: true);
            if (! $fee->isZero()) {
                $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($data['date']), $company->base_currency, $company->base_currency,
                    '1', 'Bank charge for bounced cheque ' . ($payment->cheque_number ?? ''), 'bounced_cheque', $payment->getKey());
                $draft->add(JournalLineDraft::debit(Account::system('bank_charges')->getKey(), $fee, null, 'Bounced cheque charge'));
                $draft->add(JournalLineDraft::credit($account->getKey(), $fee, null, 'Bounced cheque charge'));
                $posting->post($draft, $userId);
            }

            return $result;
        });

        return response()->json($result);
    }

    private function assertReceived(Payment $payment): void
    {
        if ($payment->direction !== 'in') {
            throw new NotFoundHttpException();
        }
    }
}
