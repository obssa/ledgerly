<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;

/** A status change the state machine does not allow. Rendered as HTTP 422. */
final class IllegalTransition extends DomainException
{
}
