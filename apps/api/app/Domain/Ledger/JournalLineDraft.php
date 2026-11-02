<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * One line of a journal entry before posting.
 * $debit / $credit are in the company's BASE currency; $amountTxn is the signed amount in the entry's
 * transaction currency (+ debit, - credit), kept for foreign-currency reporting.
 */
final readonly class JournalLineDraft
{
    /**
     * @param array{location_id?: string, class_id?: string, project_id?: string} $dimensions
     */
    private function __construct(
        public string $accountId,
        public Money $debit,
        public Money $credit,
        public Money $amountTxn,
        public ?string $description = null,
        public ?string $contactId = null,
        public array $dimensions = [],
    ) {
        if ($debit->isNegative() || $credit->isNegative()) {
            throw new InvalidArgumentException('Debit and credit must not be negative');
        }
        if (! $debit->isZero() && ! $credit->isZero()) {
            throw new InvalidArgumentException('A line is either a debit or a credit, not both');
        }
        if ($debit->isZero() && $credit->isZero()) {
            throw new InvalidArgumentException('A line must have a non-zero amount');
        }
    }

    public static function debit(string $accountId, Money $base, ?Money $txn = null, ?string $description = null, ?string $contactId = null, array $dimensions = []): self
    {
        return new self($accountId, $base, Money::zero($base->currency), $txn ?? $base, $description, $contactId, $dimensions);
    }

    public static function credit(string $accountId, Money $base, ?Money $txn = null, ?string $description = null, ?string $contactId = null, array $dimensions = []): self
    {
        return new self($accountId, Money::zero($base->currency), $base, ($txn ?? $base)->negated(), $description, $contactId, $dimensions);
    }

    public function isDebit(): bool
    {
        return ! $this->debit->isZero();
    }
}
