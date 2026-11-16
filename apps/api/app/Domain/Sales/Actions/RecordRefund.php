<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Purchases\BillPaymentPostingBuilder;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\PaymentPostingBuilder;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gives back credit (database/schema/023_refunds.sql):
 *   to a customer:   Dr receivable (the credit), Cr bank. From a credit note's credit left, or the unused part of a payment.
 *   from a supplier: Dr bank, Cr payable (the credit). From a supplier credit's credit left, or the unused part of an advance.
 * The credit leaves the books at the rate it was booked at (the credit's or the payment's); the gap is exchange gain or loss.
 */
final class RecordRefund
{
    private const MONEY_SUBTYPES = ['bank', 'cash', 'mobile_money'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PostingService $posting,
        private readonly PaymentPostingBuilder $in,
        private readonly BillPaymentPostingBuilder $out,
        private readonly ExchangeRates $rates,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param 'customer'|'supplier' $side
     * @param array{contact_id: string, account_id: string, payment_date: string, method: string, reference?: ?string, currency: string,
     *              fx_rate?: ?string, amount: string, fee?: ?string, credit_id?: ?string, payment_id?: ?string} $data
     */
    public function handle(string $side, array $data, string $userId): Payment
    {
        $company = $this->current->get();
        $currency = $data['currency'];
        $customer = $side === 'customer';

        return DB::transaction(function () use ($data, $userId, $company, $currency, $customer) {
            // Every id from the request is loaded through a company-scoped model (CLAUDE.md rule 4).
            $contact = Contact::query()->where($customer ? 'is_customer' : 'is_vendor', true)->find($data['contact_id'])
                ?? throw ValidationException::withMessages(['contact_id' => [$customer ? 'Not a customer of this company.' : 'Not a supplier of this company.']]);
            $account = Account::query()->where('is_active', true)->find($data['account_id']);
            if ($account === null || ! in_array($account->subtype, self::MONEY_SUBTYPES, true)) {
                throw ValidationException::withMessages(['account_id' => ['Choose a bank, cash or mobile-money account of this company.']]);
            }
            if (($account->currency ?? $company->base_currency) !== $currency) {
                throw ValidationException::withMessages(['account_id' => ["{$account->name} is kept in " . ($account->currency ?? $company->base_currency) . ", not {$currency}."]]);
            }
            $amount = Money::of($data['amount'], $currency);
            $fee = Money::of($data['fee'] ?? '0', $currency);
            $rate = $this->rates->forDocument($company, $currency, $data['payment_date'], $data['fx_rate'] ?? null);

            // What is given back, and the rate it sits at in the books.
            [$credit, $original, $bookedRate] = $this->source($data, $contact, $currency, $amount, $customer);

            $refundId = (string) Str::uuid7();
            DB::table('payments')->insert([
                'id' => $refundId, 'company_id' => $company->getKey(), 'direction' => $customer ? 'out' : 'in', 'kind' => 'refund',
                'contact_id' => $contact->getKey(), 'account_id' => $account->getKey(), 'payment_date' => $data['payment_date'],
                'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'currency' => $currency, 'fx_rate' => $rate,
                'amount' => $amount->toDecimalString(), 'fee' => $fee->toDecimalString(), 'withholding' => '0',
                'refunds_payment_id' => $original?->getKey(),
                'cheque_number' => ($data['method'] ?? null) === 'cheque' ? ($data['cheque_number'] ?? null) : null,
            ]);

            $common = [
                'companyId' => $company->getKey(), 'paymentId' => $refundId, 'date' => new DateTimeImmutable($data['payment_date']),
                'baseCurrency' => $company->base_currency, 'paymentRate' => $rate, 'bankAccountId' => $account->getKey(),
                'feeAccountId' => Account::system('bank_charges')->getKey(), 'withholdingAccountId' => Account::system($customer ? 'withholding_payable' : 'withholding_receivable')->getKey(),
                'fxAccountId' => Account::system('fx_gain_loss')->getKey(), 'amount' => $amount, 'fee' => $fee, 'withholding' => Money::zero($currency),
            ];
            $draft = $customer
                ? $this->out->paid(...$common, supplierId: $contact->getKey(), payableAccountId: Account::system('accounts_receivable')->getKey(),
                    allocations: [['amount' => $amount, 'bill_rate' => $bookedRate]], memo: "Refund to {$contact->name}", settledLabel: 'Credit refunded')
                : $this->in->received(...$common, customerId: $contact->getKey(), receivableAccountId: Account::system('accounts_payable')->getKey(),
                    allocations: [['amount' => $amount, 'invoice_rate' => $bookedRate]], memo: "Refund from {$contact->name}", settledLabel: 'Credit refunded');
            $entry = $this->posting->post($draft, $userId);
            DB::table('payments')->where('id', $refundId)->update(['journal_entry_id' => $entry->getKey()]);

            if ($credit !== null) {
                DB::table('payment_allocations')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'payment_id' => $refundId,
                    'document_id' => $credit->getKey(), 'amount' => $amount->toDecimalString(),
                ]);
            }

            $this->audit->record($company->getKey(), $customer ? 'refund_paid' : 'refund_received', 'payment', $refundId, null, [
                'contact' => $contact->name, 'amount' => $amount->toDecimalString(), 'currency' => $currency, 'journal_entry' => $entry->number,
                'credit' => $credit?->number, 'payment' => $original?->getKey(),
            ], userId: $userId);

            return Payment::query()->with(['allocations.document:id,number,total,amount_due,status', 'contact:id,name'])->findOrFail($refundId);
        });
    }

    /** @return array{0: ?Document, 1: ?Payment, 2: string} the credit document or the payment given back, and its rate */
    private function source(array $data, Contact $contact, string $currency, Money $amount, bool $customer): array
    {
        if (! empty($data['credit_id'])) {
            $credit = Document::query()->where('type', $customer ? 'credit_note' : 'vendor_credit')->lockForUpdate()->find($data['credit_id']);
            if ($credit === null || $credit->contact_id !== $contact->getKey() || $credit->currency !== $currency || ! in_array($credit->status, ['posted', 'part_paid'], true)) {
                throw ValidationException::withMessages(['credit_id' => ['Choose a posted credit of this ' . ($customer ? 'customer' : 'supplier') . " in {$currency} with credit left."]]);
            }
            if (Money::of((string) $credit->amount_due, $currency)->minus($amount)->isNegative()) {
                throw ValidationException::withMessages(['amount' => ["Only {$credit->amount_due} of credit is left on {$credit->number}."]]);
            }

            return [$credit, null, (string) $credit->fx_rate];
        }

        $original = Payment::query()->where(['kind' => 'payment', 'direction' => $customer ? 'in' : 'out'])->lockForUpdate()->find($data['payment_id'] ?? null);
        if ($original === null || $original->contact_id !== $contact->getKey() || $original->currency !== $currency || $original->status !== 'posted') {
            throw ValidationException::withMessages(['payment_id' => ['Choose a standing ' . ($customer ? 'payment from this customer' : 'payment to this supplier') . " in {$currency}."]]);
        }
        if (Money::of($original->unallocated(), $currency)->minus($amount)->isNegative()) {
            throw ValidationException::withMessages(['amount' => ["Only {$original->unallocated()} of that payment is unused."]]);
        }

        return [null, $original, (string) $original->fx_rate];
    }
}
