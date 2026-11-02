<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Ledger\Money;

final readonly class LineAmounts
{
    public function __construct(
        public Money $subtotal,
        public Money $tax,
        public Money $total,
    ) {
    }
}
