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
