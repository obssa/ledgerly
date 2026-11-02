<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

final class PeriodLocked extends LedgerException
{
    public static function until(string $closedUntil, string $entryDate): self
    {
        return new self("Books are closed until {$closedUntil}; cannot post an entry dated {$entryDate}.");
    }
}
