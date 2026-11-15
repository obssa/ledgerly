<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
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
 * Records a payment received from a customer: the payment, its journal entry (through the PostingService) and its
 * allocations, in one transaction. The database lowers each invoice's amount due as the allocation is inserted and
 * checks customer, currency and totals again (006, 016).
 */
final class ReceivePayment
{
    /** Accounts money can be received into. */
    private const RECEIVING_SUBTYPES = ['bank', 'cash', 'mobile_money'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PostingService $posting,
        private readonly PaymentPostingBuilder $builder,
        private readonly ExchangeRates $rates,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{contact_id: string, account_id: string, payment_date: string, method: string, reference?: ?string,
     *              currency: string, fx_rate?: ?string, amount: string, fee?: ?string, withholding?: ?string,
     *              provider_txn_id?: ?string, allocations?: list<array{invoice_id: string, amount: string}>} $data
     */
    public function handle(array $data, string $userId): Payment
    {
        $company = $this->current->get();
        $currency = $data['currency'];

        return DB::transaction(function () use ($data, $userId, $company, $currency) {
            // Every id from the request is loaded through a company-scoped model (CLAUDE.md rule 4).
            $customer = Contact::query()->where('is_customer', true)->find($data['contact_id'])
                ?? throw ValidationException::withMessages(['contact_id' => ['Not a customer of this company.']]);
            $account = Account::query()->where('is_active', true)->find($data['account_id']);
            if ($account === null || ! ($account->system_key === 'undeposited_funds' || in_array($account->subtype, self::RECEIVING_SUBTYPES, true))) {
                throw ValidationException::withMessages(['account_id' => ['Choose a bank, cash or mobile-money account of this company.']]);
            }
            if (($account->currency ?? $company->base_currency) !== $currency) {
                throw ValidationException::withMessages(['account_id' => ["{$account->name} is kept in " . ($account->currency ?? $company->base_currency) . ", not {$currency}."]]);
            }
            $rate = $this->rates->forDocument($company, $currency, $data['payment_date'], $data['fx_rate'] ?? null);

            $allocations = $this->allocations($data['allocations'] ?? [], $customer, $currency);
            $amount = Money::of($data['amount'], $currency);
            $fee = Money::of($data['fee'] ?? '0', $currency);
            $withholding = Money::of($data['withholding'] ?? '0', $currency);
            $allocated = array_reduce($allocations, fn (Money $s, array $a) => $s->plus($a['amount']), Money::zero($currency));
            if ($amount->plus($withholding)->minus($allocated)->isNegative()) {
                throw ValidationException::withMessages(['allocations' => ['More is allocated than the amount received plus the tax withheld.']]);
            }
            if ($amount->minus($fee)->isNegative()) {
                throw ValidationException::withMessages(['fee' => ['The bank fee cannot be more than the amount received.']]);
            }

            $paymentId = (string) Str::uuid7();
            DB::table('payments')->insert([
                'id' => $paymentId, 'company_id' => $company->getKey(), 'direction' => 'in', 'contact_id' => $customer->getKey(),
                'account_id' => $account->getKey(), 'payment_date' => $data['payment_date'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'currency' => $currency, 'fx_rate' => $rate,
                'amount' => $amount->toDecimalString(), 'fee' => $fee->toDecimalString(), 'withholding' => $withholding->toDecimalString(),
                'provider_txn_id' => $data['provider_txn_id'] ?? null,
            ]);

            $entry = $this->posting->post($this->builder->received(
                companyId: $company->getKey(), paymentId: $paymentId, date: new DateTimeImmutable($data['payment_date']),
                baseCurrency: $company->base_currency, paymentRate: $rate, customerId: $customer->getKey(),
                bankAccountId: $account->getKey(),
                receivableAccountId: Account::system('accounts_receivable')->getKey(),
                feeAccountId: Account::system('bank_charges')->getKey(),
                withholdingAccountId: Account::system('withholding_receivable')->getKey(),
                fxAccountId: Account::system('fx_gain_loss')->getKey(),
                amount: $amount, fee: $fee, withholding: $withholding,
                allocations: array_map(fn (array $a) => ['amount' => $a['amount'], 'invoice_rate' => $a['rate']], $allocations),
                memo: 'Payment from ' . $customer->name . (empty($data['reference']) ? '' : " ({$data['reference']})"),
            ), $userId);
            DB::table('payments')->where('id', $paymentId)->update(['journal_entry_id' => $entry->getKey()]);

            foreach ($allocations as $a) {
                DB::table('payment_allocations')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'payment_id' => $paymentId,
                    'document_id' => $a['invoice']->getKey(), 'amount' => $a['amount']->toDecimalString(),
                ]);
            }

            $this->audit->record($company->getKey(), 'payment_received', 'payment', $paymentId, null, [
                'customer' => $customer->name, 'amount' => $amount->toDecimalString(), 'currency' => $currency,
                'withholding' => $withholding->toDecimalString(), 'fee' => $fee->toDecimalString(), 'journal_entry' => $entry->number,
                'allocations' => array_map(fn (array $a) => ['invoice' => $a['invoice']->number, 'amount' => $a['amount']->toDecimalString()], $allocations),
            ], userId: $userId);

            return Payment::query()->with(['allocations.document:id,number,total,amount_due,status', 'contact:id,name'])->findOrFail($paymentId);
        });
    }

    /**
     * The invoices to settle, locked in a fixed order so two payments never deadlock, each checked to be this
     * customer's open invoice in this currency, with no more allocated than it still owes.
     *
     * @param list<array{invoice_id: string, amount: string}> $requested
     * @return list<array{invoice: Document, amount: Money, rate: string}>
     */
    private function allocations(array $requested, Contact $customer, string $currency): array
    {
        $invoices = Document::query()->whereIn('id', array_column($requested, 'invoice_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $out = [];
        $errors = [];
        foreach (array_values($requested) as $i => $r) {
            $invoice = $invoices[$r['invoice_id']] ?? null;
            $amount = Money::of($r['amount'], $currency);
            if ($invoice === null || $invoice->type !== 'invoice' || $invoice->contact_id !== $customer->getKey()) {
                $errors["allocations.{$i}.invoice_id"] = ["Not an invoice of {$customer->name}."];
            } elseif (! in_array($invoice->status, ['posted', 'part_paid'], true)) {
                $errors["allocations.{$i}.invoice_id"] = ["{$invoice->label()} is not open for payment."];
            } elseif ($invoice->currency !== $currency) {
                $errors["allocations.{$i}.invoice_id"] = ["{$invoice->number} is in {$invoice->currency}, not {$currency}."];
            } elseif (Money::of($invoice->amount_due, $currency)->minus($amount)->isNegative()) {
                $errors["allocations.{$i}.amount"] = ["{$invoice->number} still owes only {$invoice->amount_due}."];
            } else {
                $out[] = ['invoice' => $invoice, 'amount' => $amount, 'rate' => (string) $invoice->fx_rate];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
