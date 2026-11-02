<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use DomainException;

/** Base class for ledger rule violations; rendered as HTTP 422 by bootstrap/app.php. */
class LedgerException extends DomainException
{
}
