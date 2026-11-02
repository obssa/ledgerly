<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\UnbalancedEntry;
use DateTimeImmutable;

/**
 * A journal entry before posting. The PostingService refuses anything that is not balanced;
 * the database checks again at commit (database/schema/002_ledger.sql).
 */
final class JournalDraft
{
    /** @var list<JournalLineDraft> */
    private array $lines = [];

    public function __construct(
        public readonly string $companyId,
        public readonly DateTimeImmutable $date,
        public readonly string $baseCurrency,
        public readonly string $currency,
        public readonly string $fxRate = '1',
        public readonly ?string $memo = null,
        public readonly string $sourceType = 'manual',
        public readonly ?string $sourceId = null,
    ) {
    }

    public function add(JournalLineDraft $line): self
    {
        if ($line->debit->currency !== $this->baseCurrency) {
            throw new UnbalancedEntry("Line amounts must be in the base currency {$this->baseCurrency}");
        }
        $this->lines[] = $line;

        return $this;
    }

    /** @return list<JournalLineDraft> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, JournalLineDraft $l) => $sum->plus($l->debit), Money::zero($this->baseCurrency));
    }

    public function totalCredit(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, JournalLineDraft $l) => $sum->plus($l->credit), Money::zero($this->baseCurrency));
    }

    public function assertBalanced(): void
    {
        if (count($this->lines) < 2) {
            throw new UnbalancedEntry('A journal entry needs at least two lines');
        }

        $debit = $this->totalDebit();
        $credit = $this->totalCredit();

        if (! $debit->equals($credit)) {
            throw new UnbalancedEntry("Journal entry is unbalanced: debits {$debit} vs credits {$credit}");
        }
    }

    /** The reversing draft: same lines with debits and credits swapped. */
    public static function reversalOf(self $original, DateTimeImmutable $date, string $sourceId): self
    {
        $reversal = new self(
            $original->companyId, $date, $original->baseCurrency, $original->currency, $original->fxRate,
            'Reversal: ' . ($original->memo ?? ''), 'reversal', $sourceId,
        );

        foreach ($original->lines as $line) {
            $reversal->add($line->isDebit()
                ? JournalLineDraft::credit($line->accountId, $line->debit, $line->amountTxn, $line->description, $line->contactId, $line->dimensions)
                : JournalLineDraft::debit($line->accountId, $line->credit, $line->amountTxn->negated(), $line->description, $line->contactId, $line->dimensions));
        }

        return $reversal;
    }
}
