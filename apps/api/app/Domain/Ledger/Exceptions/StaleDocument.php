<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

/** Optimistic locking: the document changed since the client read it. Rendered as HTTP 409. */
final class StaleDocument extends LedgerException
{
    public static function for(string $number): self
    {
        return new self("{$number} was changed by someone else. Reload it and try again.");
    }
}
