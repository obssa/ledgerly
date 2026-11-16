<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Journal drafts for money moving between the company's own accounts.
 *
 *   Transfer: Dr destination account            Cr source account          (+ Dr fees, Cr source)
 *   Deposit:  Dr bank (receipts - fee) + Dr fee  Cr undeposited funds (receipts)
 *
 * Receipts (cash, cheques, mobile-money settlements) first land in "Undeposited funds"; a deposit moves
 * one or many of them into the bank in a single line, so the ledger matches the bank's deposit slip.
 */
final class BankingPostingBuilder
{
    public function transfer(
        string $companyId,
        DateTimeImmutable $date,
        string $fromAccountId,
        string $toAccountId,
        Money $amount,
        ?Money $fee = null,
        ?string $feeAccountId = null,
        ?string $memo = null,
    ): JournalDraft {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }
        $this->assertPositive($amount);

        $draft = new JournalDraft($companyId, $date, $amount->currency, $amount->currency, '1', $memo ?? 'Transfer', 'transfer');
        $draft->add(JournalLineDraft::debit($toAccountId, $amount, description: 'Transfer in'));
        $draft->add(JournalLineDraft::credit($fromAccountId, $amount, description: 'Transfer out'));

        if ($fee !== null && ! $fee->isZero()) {
            $draft->add(JournalLineDraft::debit($feeAccountId ?? throw new InvalidArgumentException('Fee needs a fee account'), $fee, description: 'Transfer fee'));
            $draft->add(JournalLineDraft::credit($fromAccountId, $fee, description: 'Transfer fee'));
        }

        $draft->assertBalanced();

        return $draft;
    }

    /**
     * A transfer where at least one side is held in a foreign currency: birr to dollars, dollars to birr, or dollars to
     * dollars. Each side is valued in the base currency at the day's rate of the foreign currency (IAS 21: spot rate at
     * the date of the transaction); birr is what was actually sent or received. What the bank kept in its rate is the
     * difference, booked to exchange gain or loss. The fee is charged in the source account's currency.
     *
     *   Dr destination (received x rate)   Cr source (sent x rate)   Dr/Cr exchange gain or loss (the difference)
     *
     * The entry is in the foreign currency at the day's rate; every line's transaction amount is in that currency.
     */
    public function currencyTransfer(
        string $companyId,
        DateTimeImmutable $date,
        string $baseCurrency,
        string $fromAccountId,
        string $toAccountId,
        Money $sent,
        Money $received,
        string $rate,
        string $fxAccountId,
        ?Money $fee = null,
        ?string $feeAccountId = null,
        ?string $memo = null,
    ): JournalDraft {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }
        $this->assertPositive($sent);
        $this->assertPositive($received);
        $foreign = array_values(array_unique(array_filter([$sent->currency, $received->currency], fn ($c) => $c !== $baseCurrency)));
        if (count($foreign) !== 1) {
            throw new InvalidArgumentException('A currency transfer is between the base currency and one foreign currency, or within one foreign currency.');
        }
        $currency = $foreign[0];
        if ($sent->currency === $received->currency && ! $sent->equals($received)) {
            throw new InvalidArgumentException('Within one currency the amount received is the amount sent.');
        }

        $toBase = fn (Money $m): Money => $m->currency === $baseCurrency ? $m : $m->convertTo($baseCurrency, $rate);
        $toTxn = fn (Money $m): Money => $m->currency === $currency ? $m
            : $m->convertTo($currency, \Brick\Math\BigDecimal::one()->dividedBy($rate, 12, \App\Domain\Ledger\Rounding::halfUp()));
        $sentBase = $toBase($sent);
        $receivedBase = $toBase($received);

        $draft = new JournalDraft($companyId, $date, $baseCurrency, $currency, $rate, $memo ?? 'Transfer', 'transfer');
        $draft->add(JournalLineDraft::debit($toAccountId, $receivedBase, $toTxn($received), 'Transfer in'));
        $draft->add(JournalLineDraft::credit($fromAccountId, $sentBase, $toTxn($sent), 'Transfer out'));
        $difference = $sentBase->minus($receivedBase);
        if (! $difference->isZero()) {
            $draft->add($difference->isNegative()
                ? JournalLineDraft::credit($fxAccountId, $difference->negated(), Money::zero($currency), 'Exchange gain on transfer')
                : JournalLineDraft::debit($fxAccountId, $difference, Money::zero($currency), 'Exchange loss on transfer'));
        }
        if ($fee !== null && ! $fee->isZero()) {
            $draft->add(JournalLineDraft::debit($feeAccountId ?? throw new InvalidArgumentException('Fee needs a fee account'), $toBase($fee), $toTxn($fee), 'Transfer fee'));
            $draft->add(JournalLineDraft::credit($fromAccountId, $toBase($fee), $toTxn($fee), 'Transfer fee'));
        }

        $draft->assertBalanced();

        return $draft;
    }

    /** @param list<Money> $receipts amounts of the receipts grouped on this deposit slip */
    public function deposit(
        string $companyId,
        DateTimeImmutable $date,
        string $bankAccountId,
        string $undepositedFundsAccountId,
        array $receipts,
        ?Money $fee = null,
        ?string $feeAccountId = null,
        ?string $slipNumber = null,
    ): JournalDraft {
        if ($receipts === []) {
            throw new InvalidArgumentException('A deposit needs at least one receipt.');
        }

        $total = array_reduce($receipts, fn (Money $sum, Money $r) => $sum->plus($r), Money::zero($receipts[0]->currency));
        $this->assertPositive($total);
        $fee ??= Money::zero($total->currency);

        $draft = new JournalDraft($companyId, $date, $total->currency, $total->currency, '1', 'Bank deposit ' . ($slipNumber ?? ''), 'deposit');
        $draft->add(JournalLineDraft::debit($bankAccountId, $total->minus($fee), description: 'Deposit ' . ($slipNumber ?? '')));
        if (! $fee->isZero()) {
            $draft->add(JournalLineDraft::debit($feeAccountId ?? throw new InvalidArgumentException('Fee needs a fee account'), $fee, description: 'Deposit fee'));
        }
        $draft->add(JournalLineDraft::credit($undepositedFundsAccountId, $total, description: count($receipts) . ' receipt(s)'));

        $draft->assertBalanced();

        return $draft;
    }

    private function assertPositive(Money $amount): void
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }
}
