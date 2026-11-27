<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The journal entry for a payment received from a customer (no database access; unit-tested).
 *
 *   Dr Bank / cash / wallet          what arrived, less the bank's fee          (payment rate)
 *   Dr Bank charges                   the fee the bank kept                      (payment rate)
 *   Dr Withholding receivable         tax the customer withheld, on certificate  (payment rate)
 *   Cr Accounts receivable            each invoice settled, at that invoice's own rate;
 *                                     anything not allocated stays as the customer's credit (payment rate)
 *   Dr/Cr Foreign exchange gain/loss  the difference, when the rate moved since the invoice
 *
 * What the payment settles is the amount received plus the tax withheld.
 */
final class PaymentPostingBuilder
{
    /**
     * @param list<array{amount: Money, invoice_rate: string}> $allocations in the payment currency
     */
    public function received(
        string $companyId,
        string $paymentId,
        DateTimeImmutable $date,
        string $baseCurrency,
        string $paymentRate,
        string $customerId,
        string $bankAccountId,
        string $receivableAccountId,
        string $feeAccountId,
        string $withholdingAccountId,
        string $fxAccountId,
        Money $amount,
        Money $fee,
        Money $withholding,
        array $allocations,
        ?string $memo = null,
        string $settledLabel = 'Invoice settled',
        ?Money $accountAmount = null,
    ): JournalDraft {
        $currency = $amount->currency;
        if ($amount->isZero() || $amount->isNegative()) {
            throw new InvalidArgumentException('A payment must be more than zero.');
        }
        if ($fee->isNegative() || $withholding->isNegative()) {
            throw new InvalidArgumentException('The fee and the tax withheld cannot be negative.');
        }
        if ($amount->minus($fee)->isNegative()) {
            throw new InvalidArgumentException('The bank fee cannot be more than the amount received.');
        }

        $settles = $amount->plus($withholding);
        $allocated = array_reduce($allocations, fn (Money $sum, array $a) => $sum->plus($a['amount']), Money::zero($currency));
        if ($settles->minus($allocated)->isNegative()) {
            throw new InvalidArgumentException('More is allocated to invoices than the payment settles.');
        }

        $base = fn (Money $m, string $rate): Money => $m->convertTo($baseCurrency, $rate)->roundToCurrency();
        $draft = new JournalDraft($companyId, $date, $baseCurrency, $currency, $paymentRate, $memo ?? 'Payment received', 'payment', $paymentId);

        $received = $amount->minus($fee);
        if (! $received->isZero()) {
            // Through an account in the company's currency (107): exactly what it received, less the fee at the bank's rate.
            $bank = $accountAmount !== null ? $accountAmount->minus($base($fee, $paymentRate)) : $base($received, $paymentRate);
            $draft->add(JournalLineDraft::debit($bankAccountId, $bank, $received, 'Payment received', $customerId));
        }
        if (! $fee->isZero()) {
            $draft->add(JournalLineDraft::debit($feeAccountId, $base($fee, $paymentRate), $fee, 'Bank fee on payment'));
        }
        if (! $withholding->isZero()) {
            $draft->add(JournalLineDraft::debit($withholdingAccountId, $base($withholding, $paymentRate), $withholding, 'Tax withheld by the customer', $customerId));
        }

        foreach ($allocations as $a) {
            $draft->add(JournalLineDraft::credit($receivableAccountId, $base($a['amount'], $a['invoice_rate']), $a['amount'], $settledLabel, $customerId));
        }
        $unallocated = $settles->minus($allocated);
        if (! $unallocated->isZero()) {
            $draft->add(JournalLineDraft::credit($receivableAccountId, $base($unallocated, $paymentRate), $unallocated, 'Customer credit (not allocated)', $customerId));
        }

        // Debits at today's rate, credits at each invoice's rate: the gap is the realised exchange difference.
        $gap = $draft->totalDebit()->minus($draft->totalCredit());
        if (! $gap->isZero()) {
            $zero = Money::zero($currency);
            $draft->add($gap->isNegative()
                ? JournalLineDraft::debit($fxAccountId, $gap->negated(), $zero, 'Exchange loss on payment')
                : JournalLineDraft::credit($fxAccountId, $gap, $zero, 'Exchange gain on payment'));
        }

        $draft->assertBalanced();

        return $draft;
    }
}
