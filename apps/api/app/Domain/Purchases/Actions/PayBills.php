<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Purchases\BillPaymentPostingBuilder;
use App\Domain\Sales\Models\Contact;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment made to a supplier: the payment, its journal entry (through the PostingService) and its
 * allocations to the supplier's bills, in one transaction. The database lowers each bill's amount due as the allocation
 * is inserted and checks supplier, currency, totals and withholding exemption again (006, 016, 020).
 */
final class PayBills
{
    /** Accounts money can be paid from. */
    private const PAYING_SUBTYPES = ['bank', 'cash', 'mobile_money'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PostingService $posting,
        private readonly BillPaymentPostingBuilder $builder,
        private readonly ExchangeRates $rates,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{contact_id: string, account_id: string, payment_date: string, method: string, reference?: ?string,
     *              currency: string, fx_rate?: ?string, amount: string, fee?: ?string, withholding?: ?string,
     *              withholding_certificate?: ?string, allocations?: list<array{bill_id: string, amount: string}>} $data
     */
    public function handle(array $data, string $userId): Payment
    {
        $company = $this->current->get();
        $currency = $data['currency'];

        return DB::transaction(function () use ($data, $userId, $company, $currency) {
            // Every id from the request is loaded through a company-scoped model (CLAUDE.md rule 4).
            $supplier = Contact::query()->where('is_vendor', true)->find($data['contact_id'])
                ?? throw ValidationException::withMessages(['contact_id' => ['Not a supplier of this company.']]);
            $account = Account::query()->where('is_active', true)->find($data['account_id']);
            if ($account === null || ! in_array($account->subtype, self::PAYING_SUBTYPES, true)) {
                throw ValidationException::withMessages(['account_id' => ['Choose a bank, cash or mobile-money account of this company.']]);
            }
            if (($account->currency ?? $company->base_currency) !== $currency) {
                throw ValidationException::withMessages(['account_id' => ["{$account->name} is kept in " . ($account->currency ?? $company->base_currency) . ", not {$currency}."]]);
            }
            $rate = $this->rates->forDocument($company, $currency, $data['payment_date'], $data['fx_rate'] ?? null);

            $allocations = $this->allocations($data['allocations'] ?? [], $supplier, $currency);
            $amount = Money::of($data['amount'], $currency);
            $fee = Money::of($data['fee'] ?? '0', $currency);
            $withholding = Money::of($data['withholding'] ?? '0', $currency);
            if (! $withholding->isZero() && $supplier->withholding_exempt) {
                throw ValidationException::withMessages(['withholding' => ["{$supplier->name} holds a withholding exemption certificate ({$supplier->withholding_exempt_reason})."]]);
            }
            $allocated = array_reduce($allocations, fn (Money $s, array $a) => $s->plus($a['amount']), Money::zero($currency));
            if ($amount->plus($withholding)->minus($allocated)->isNegative()) {
                throw ValidationException::withMessages(['allocations' => ['More is allocated than the amount paid plus the tax withheld.']]);
            }

            $paymentId = (string) Str::uuid7();
            DB::table('payments')->insert([
                'id' => $paymentId, 'company_id' => $company->getKey(), 'direction' => 'out', 'contact_id' => $supplier->getKey(),
                'account_id' => $account->getKey(), 'payment_date' => $data['payment_date'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'currency' => $currency, 'fx_rate' => $rate,
                'amount' => $amount->toDecimalString(), 'fee' => $fee->toDecimalString(), 'withholding' => $withholding->toDecimalString(),
                'withholding_certificate' => $withholding->isZero() ? null : ($data['withholding_certificate'] ?? null),
                'cheque_number' => ($data['method'] ?? null) === 'cheque' ? ($data['cheque_number'] ?? null) : null,
            ]);

            $entry = $this->posting->post($this->builder->paid(
                companyId: $company->getKey(), paymentId: $paymentId, date: new DateTimeImmutable($data['payment_date']),
                baseCurrency: $company->base_currency, paymentRate: $rate, supplierId: $supplier->getKey(),
                bankAccountId: $account->getKey(),
                payableAccountId: Account::system('accounts_payable')->getKey(),
                feeAccountId: Account::system('bank_charges')->getKey(),
                withholdingAccountId: Account::system('withholding_payable')->getKey(),
                fxAccountId: Account::system('fx_gain_loss')->getKey(),
                amount: $amount, fee: $fee, withholding: $withholding,
                allocations: array_map(fn (array $a) => ['amount' => $a['amount'], 'bill_rate' => $a['rate']], $allocations),
                memo: 'Payment to ' . $supplier->name . (empty($data['reference']) ? '' : " ({$data['reference']})"),
            ), $userId);
            DB::table('payments')->where('id', $paymentId)->update(['journal_entry_id' => $entry->getKey()]);

            foreach ($allocations as $a) {
                DB::table('payment_allocations')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'payment_id' => $paymentId,
                    'document_id' => $a['bill']->getKey(), 'amount' => $a['amount']->toDecimalString(),
                ]);
            }

            $this->audit->record($company->getKey(), 'payment_made', 'payment', $paymentId, null, [
                'supplier' => $supplier->name, 'amount' => $amount->toDecimalString(), 'currency' => $currency,
                'withholding' => $withholding->toDecimalString(), 'withholding_certificate' => $data['withholding_certificate'] ?? null,
                'fee' => $fee->toDecimalString(), 'journal_entry' => $entry->number,
                'allocations' => array_map(fn (array $a) => ['bill' => $a['bill']->number, 'amount' => $a['amount']->toDecimalString()], $allocations),
            ], userId: $userId);

            return Payment::query()->with(['allocations.document:id,number,reference,total,amount_due,status', 'contact:id,name'])->findOrFail($paymentId);
        });
    }

    /**
     * The bills to settle, locked in a fixed order so two payments never deadlock, each checked to be this supplier's
     * open bill in this currency, with no more allocated than is still to pay.
     *
     * @param list<array{bill_id: string, amount: string}> $requested
     * @return list<array{bill: Document, amount: Money, rate: string}>
     */
    private function allocations(array $requested, Contact $supplier, string $currency): array
    {
        $bills = Document::query()->whereIn('id', array_column($requested, 'bill_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $out = [];
        $errors = [];
        foreach (array_values($requested) as $i => $r) {
            $bill = $bills[$r['bill_id']] ?? null;
            $amount = Money::of($r['amount'], $currency);
            if ($bill === null || $bill->type !== 'bill' || $bill->contact_id !== $supplier->getKey()) {
                $errors["allocations.{$i}.bill_id"] = ["Not a bill of {$supplier->name}."];
            } elseif (! in_array($bill->status, ['posted', 'part_paid'], true)) {
                $errors["allocations.{$i}.bill_id"] = ["{$bill->label()} is not open for payment."];
            } elseif ($bill->currency !== $currency) {
                $errors["allocations.{$i}.bill_id"] = ["{$bill->number} is in {$bill->currency}, not {$currency}."];
            } elseif (Money::of($bill->amount_due, $currency)->minus($amount)->isNegative()) {
                $errors["allocations.{$i}.amount"] = ["Only {$bill->amount_due} is still to pay on {$bill->number}."];
            } else {
                $out[] = ['bill' => $bill, 'amount' => $amount, 'rate' => (string) $bill->fx_rate];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
