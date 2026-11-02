<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Brick\Math\RoundingMode;

/**
 * brick/math renamed its rounding cases (HALF_UP -> HalfUp) between versions.
 * Resolving the case here keeps the domain code working on whichever version Laravel pulls in.
 */
final class Rounding
{
    public static function halfUp(): RoundingMode
    {
        return defined(RoundingMode::class . '::HalfUp')
            ? constant(RoundingMode::class . '::HalfUp')
            : constant(RoundingMode::class . '::HALF_UP');
    }
}
