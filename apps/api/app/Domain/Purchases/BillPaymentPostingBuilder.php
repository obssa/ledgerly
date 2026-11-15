<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The journal entry for a payment made to a supplier (no database access; unit-tested).
 *
 *   Dr Accounts payable               each bill settled, at that bill's own rate;
 *                                     anything not allocated is an advance to the supplier (payment rate)
 *   Cr Bank / cash / wallet           what left the account: the amount paid plus the bank's fee (payment rate)
 *   Dr Bank charges                   the fee the bank took                                        (payment rate)
 *   Cr Withholding tax payable        tax withheld from the supplier, owed to the tax authority   (payment rate)
 *   Dr/Cr Foreign exchange gain/loss  the difference, when the rate moved since the bill
 *
 * What the payment settles is the amount paid plus the tax withheld.
 */
final class BillPaymentPostingBuilder
{
    /**
     * @param list<array{amount: Money, bill_rate: string}> $allocations in the payment currency
     */
    public function paid(
        string $companyId,
        string $paymentId,
        DateTimeImmutable $date,
        string $baseCurrency,
        string $paymentRate,
        string $supplierId,
        string $bankAccountId,
        string $payableAccountId,
        string $feeAccountId,
        string $withholdingAccountId,
        string $fxAccountId,
        Money $amount,
        Money $fee,
        Money $withholding,
        array $allocations,
        ?string $memo = null,
    ): JournalDraft {
        $currency = $amount->currency;
        if ($amount->isZero() || $amount->isNegative()) {
            throw new InvalidArgumentException('A payment must be more than zero.');
        }
        if ($fee->isNegative() || $withholding->isNegative()) {
            throw new InvalidArgumentException('The fee and the tax withheld cannot be negative.');
        }

        $settles = $amount->plus($withholding);
        $allocated = array_reduce($allocations, fn (Money $sum, array $a) => $sum->plus($a['amount']), Money::zero($currency));
        if ($settles->minus($allocated)->isNegative()) {
            throw new InvalidArgumentException('More is allocated to bills than the payment settles.');
        }

        $base = fn (Money $m, string $rate): Money => $m->convertTo($baseCurrency, $rate)->roundToCurrency();
        $draft = new JournalDraft($companyId, $date, $baseCurrency, $currency, $paymentRate, $memo ?? 'Payment made', 'payment', $paymentId);

        foreach ($allocations as $a) {
            $draft->add(JournalLineDraft::debit($payableAccountId, $base($a['amount'], $a['bill_rate']), $a['amount'], 'Bill paid', $supplierId));
        }
        $unallocated = $settles->minus($allocated);
        if (! $unallocated->isZero()) {
            $draft->add(JournalLineDraft::debit($payableAccountId, $base($unallocated, $paymentRate), $unallocated, 'Advance to supplier (not allocated)', $supplierId));
        }
        if (! $fee->isZero()) {
            $draft->add(JournalLineDraft::debit($feeAccountId, $base($fee, $paymentRate), $fee, 'Bank fee on payment'));
        }

        $out = $amount->plus($fee);
        $draft->add(JournalLineDraft::credit($bankAccountId, $base($out, $paymentRate), $out, 'Payment made', $supplierId));
        if (! $withholding->isZero()) {
            $draft->add(JournalLineDraft::credit($withholdingAccountId, $base($withholding, $paymentRate), $withholding, 'Tax withheld from the supplier', $supplierId));
        }

        // Debits at each bill's rate, credits at today's: the gap is the realised exchange difference.
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
