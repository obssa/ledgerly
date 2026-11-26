<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\CashForecast;
use App\Domain\Banking\ChequeNumbers;
use App\Domain\Ledger\Models\Account;
use App\Domain\Purchases\PaymentFile;
use App\Domain\Ledger\Currency;
use App\Domain\Ledger\Money;
use App\Domain\Purchases\Actions\PayBills;
use App\Domain\Purchases\WithholdingAdvice;
use App\Domain\Sales\Actions\AllocatePayment;
use App\Domain\Sales\Actions\ReversePayment;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module 3, payments made: paying one or more of a supplier's bills from a bank, cash or mobile-money account, with any
 * tax withheld (on a certificate given to the supplier) and any fee the bank took. Money leaves the company, so only
 * accountants, owners and admins pay (payments.pay). Recorded once; a payment that did not go through is reversed.
 */
final class PaymentMadeController extends Controller
{
    use Concerns\FiltersByDate;

    public function __construct(private readonly CurrentCompany $current, private readonly WithholdingAdvice $advice)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('payments.view');
        $range = $this->dateRange($request);
        $q = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json(Payment::query()->where(['direction' => 'out', 'kind' => 'payment'])
            ->when($q['contact_id'] ?? null, fn ($b, $id) => $b->where('contact_id', $id))
            ->with('contact:id,name')->withCount('allocations')
            ->when(true, fn ($q) => $this->withinDates($q, 'payment_date', $range))   // the period asked for (FiltersByDate)
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->cursorPaginate(50));
    }

    public function show(Payment $paymentMade): JsonResponse
    {
        $this->authorizeCompany('payments.view');

        return response()->json([
            ...$paymentMade->load(['contact:id,name,tax_id,payout', 'allocations.document:id,number,reference,issue_date,total,amount_due,status'])->toArray(),
            'unallocated' => $paymentMade->unallocated(),
            'can' => ['reverse' => (bool) $this->current->membership()?->can('payments.reverse'), 'allocate' => (bool) $this->current->membership()?->can('payments.pay'),
                'refund' => (bool) $this->current->membership()?->can('payments.receive')],
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

    /**
     * Module 3: the bank payment file. The supplier payments of a day by bank transfer (or mobile money), from one
     * money account or all of them, as a CSV for the bank's bulk transfer. It moves nothing; a download is audited.
     */
    public function bankFile(Request $request, PaymentFile $file): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $q = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'uuid'],
            'method' => ['nullable', Rule::in(PaymentFile::METHODS)],
            'download' => ['nullable', 'boolean'],
        ]);
        $account = null;
        if (($q['account_id'] ?? null) !== null) {
            $account = Account::query()->whereIn('subtype', CashForecast::MONEY)->find($q['account_id'])   // the company's own (rule 4)
                ?? throw ValidationException::withMessages(['account_id' => ['Choose a bank or mobile money account of this company.']]);
        }
        $result = $file->build($this->current->id(), $q['date'] ?? $this->current->today(), $account?->getKey(), $q['method'] ?? 'bank_transfer');
        if ($request->boolean('download')) {
            app(AuditLogger::class)->record($this->current->id(), 'bank_file_downloaded', 'company', $this->current->id(), null, ['date' => $result['date'],
                'account_id' => $result['account_id'], 'method' => $result['method'], 'payments' => array_column($result['rows'], 'payment_id'), 'totals' => $result['totals']],
                userId: $request->user()->getKey());
        }

        return response()->json($result);
    }

    /** Module 3: batch payments. Several suppliers' bills paid in one run, one payment each, all or none (not by cheque). */
    public function batch(Request $request, \App\Domain\Purchases\BatchPayments $batches): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . app(CurrentCompany::class)->today()],
            // A cheque carries its own number from the cheque book: those are written one by one.
            'method' => ['required', Rule::in(array_values(array_diff(PaymentReceivedController::METHODS, ['cheque'])))],
            'reference' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::in(Currency::all())],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'bills' => ['required', 'array', 'min:1', 'max:' . \App\Domain\Purchases\BatchPayments::MAX_BILLS],
            'bills.*.bill_id' => ['required', 'uuid', 'distinct'],
            'bills.*.amount' => ['nullable', 'decimal:0,2', 'gt:0'],
            'withholding' => ['nullable', 'array', 'max:200'],
            'withholding.*.contact_id' => ['required', 'uuid', 'distinct'],
            'withholding.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
            'withholding.*.certificate' => ['required', 'string', 'max:60'],
        ], [
            'bills.*.bill_id.distinct' => 'Each bill appears once.',
            'withholding.*.certificate.required' => 'Give the number of the withholding receipt handed to the supplier.',
        ]);

        return response()->json($batches->pay($data, $request->user()->getKey()), 201);
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
            'cheque_number' => ChequeNumbers::RULE,
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.bill_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], [
            'allocations.*.bill_id.distinct' => 'Each bill appears once.',
            'withholding_certificate.required_unless' => 'Give the number of the withholding receipt handed to the supplier.',
        ]);

        ChequeNumbers::assertWritable($data);
        $payment = $pay->handle($data, $request->user()->getKey());

        return response()->json([...$payment->toArray(), 'warnings' => $this->warnings($payment)], 201);
    }

    /** Applies what the payment did not allocate (an advance) to the supplier's open bills. */
    public function allocate(Request $request, Payment $paymentMade, AllocatePayment $allocate): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.bill_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ], ['allocations.*.bill_id.distinct' => 'Each bill appears once.']);

        return response()->json($allocate->handle($paymentMade,
            array_map(fn (array $a) => ['document_id' => $a['bill_id'], 'amount' => $a['amount']], $data['allocations']), $request->user()->getKey()));
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
