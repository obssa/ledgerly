<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\Payment;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uses what a recorded payment did not allocate: a customer's credit against a later invoice, an advance to a supplier
 * against a later bill. The ledger already holds the money on the contact's receivable or payable; allocating only
 * records which document it settles (the database lowers the amount due, 006, and checks contact, currency and totals,
 * 016, 020).
 *
 * In a foreign currency the advance sits at the payment's rate and the document at its own, so one entry moves the
 * difference to foreign exchange gain or loss (source 'payment_allocation', reversed with the payment).
 */
final class AllocatePayment
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param list<array{document_id: string, amount: string}> $requested */
    public function handle(Payment $payment, array $requested, string $userId): Payment
    {
        $company = $this->current->get();
        $settles = $payment->direction === 'in' ? 'invoice' : 'bill';
        $key = "{$settles}_id";

        return DB::transaction(function () use ($payment, $requested, $userId, $company, $settles, $key) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status !== 'posted') {
                throw ValidationException::withMessages(['allocations' => ['This payment is reversed: it settles nothing.']]);
            }
            $currency = $payment->currency;
            $allocated = Money::of((string) DB::table('payment_allocations')->where('payment_id', $payment->getKey())->sum('amount'), $currency);
            $left = Money::of((string) $payment->amount, $currency)->plus(Money::of((string) $payment->withholding, $currency))->minus($allocated);

            // Every id from the request is loaded through the company-scoped model, locked in a fixed order.
            $docs = Document::query()->whereIn('id', array_column($requested, 'document_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = Money::zero($currency);
            $errors = [];
            $rows = [];
            foreach (array_values($requested) as $i => $r) {
                $doc = $docs[$r['document_id']] ?? null;
                $amount = Money::of($r['amount'], $currency);
                if ($doc === null || $doc->type !== $settles || $doc->contact_id !== $payment->contact_id) {
                    $errors["allocations.{$i}.{$key}"] = ["Not an open {$settles} of this " . ($settles === 'bill' ? 'supplier.' : 'customer.')];
                } elseif (! in_array($doc->status, ['posted', 'part_paid'], true) || $doc->currency !== $currency) {
                    $errors["allocations.{$i}.{$key}"] = ["{$doc->label()} is not open in {$currency}."];
                } elseif (Money::of((string) $doc->amount_due, $currency)->minus($amount)->isNegative()) {
                    $errors["allocations.{$i}.amount"] = ["Only {$doc->amount_due} is still due on {$doc->number}."];
                } else {
                    $rows[] = ['doc' => $doc, 'amount' => $amount];
                    $total = $total->plus($amount);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            if ($left->minus($total)->isNegative()) {
                throw ValidationException::withMessages(['allocations' => ["Only {$left->toDecimalString()} of this payment is not allocated yet."]]);
            }

            foreach ($rows as $row) {
                DB::table('payment_allocations')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'payment_id' => $payment->getKey(),
                    'document_id' => $row['doc']->getKey(), 'amount' => $row['amount']->toDecimalString(),
                ]);
            }
            $this->postExchangeDifference($payment, $rows, $userId);

            $this->audit->record($company->getKey(), 'payment_allocated', 'payment', $payment->getKey(), null, [
                'allocations' => array_map(fn (array $r) => [$settles => $r['doc']->number, 'amount' => $r['amount']->toDecimalString()], $rows),
            ], userId: $userId);

            return Payment::query()->with(['allocations.document:id,number,reference,issue_date,total,amount_due,status', 'contact:id,name'])->findOrFail($payment->getKey());
        });
    }

    /**
     * The advance was booked at the payment's rate; the documents it now settles carry their own. Moves each allocation
     * from the one rate to the other on the contact's account and books the gap. Nothing to do in the base currency.
     *
     * @param list<array{doc: Document, amount: Money}> $rows
     */
    private function postExchangeDifference(Payment $payment, array $rows, string $userId): void
    {
        $company = $this->current->get();
        $base = $company->base_currency;
        $rows = array_filter($rows, fn (array $r) => (string) $r['doc']->fx_rate !== (string) $payment->fx_rate);
        if ($payment->currency === $base || $rows === []) {
            return;
        }

        $control = Account::system($payment->direction === 'in' ? 'accounts_receivable' : 'accounts_payable')->getKey();
        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($this->current->today()), $base, $payment->currency,
            (string) $payment->fx_rate, 'Exchange difference on applying a payment', 'payment_allocation', $payment->getKey());
        $atRate = fn (Money $m, string $rate): Money => $m->convertTo($base, $rate)->roundToCurrency();
        foreach ($rows as $r) {
            $atPayment = $atRate($r['amount'], (string) $payment->fx_rate);
            $atDocument = $atRate($r['amount'], (string) $r['doc']->fx_rate);
            if ($payment->direction === 'in') {
                // Customer credit (a credit on receivable at the payment's rate) settles an invoice (a debit at its rate).
                $draft->add(JournalLineDraft::debit($control, $atPayment, $r['amount'], 'Customer credit used', $payment->contact_id));
                $draft->add(JournalLineDraft::credit($control, $atDocument, $r['amount'], "Invoice {$r['doc']->number} settled", $payment->contact_id));
            } else {
                // An advance (a debit on payable at the payment's rate) settles a bill (a credit at its rate).
                $draft->add(JournalLineDraft::credit($control, $atPayment, $r['amount'], 'Advance used', $payment->contact_id));
                $draft->add(JournalLineDraft::debit($control, $atDocument, $r['amount'], "Bill {$r['doc']->number} paid", $payment->contact_id));
            }
        }
        $gap = $draft->totalDebit()->minus($draft->totalCredit());
        if ($gap->isZero()) {
            return;
        }
        $fx = Account::system('fx_gain_loss')->getKey();
        $zero = Money::zero($payment->currency);
        $draft->add($gap->isNegative()
            ? JournalLineDraft::debit($fx, $gap->negated(), $zero, 'Exchange loss')
            : JournalLineDraft::credit($fx, $gap, $zero, 'Exchange gain'));
        $this->posting->post($draft, $userId);
    }
}
