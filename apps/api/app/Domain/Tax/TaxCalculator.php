<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Ledger\Money;
use Brick\Math\BigDecimal;

/**
 * Line and document tax maths. Each line is rounded to the currency's decimals, then lines are summed,
 * which is how invoices are printed and how most African tax authorities validate e-invoices.
 */
final class TaxCalculator
{
    /**
     * @param string $quantity     e.g. "240"
     * @param string $discountRate 0..1, e.g. "0.05" for 5%
     * @param string $taxRate      0..1, e.g. "0.15" for 15% VAT
     * @param bool   $inclusive    true when $unitPrice already includes tax
     */
    public function line(Money $unitPrice, string $quantity, string $discountRate = '0', string $taxRate = '0', bool $inclusive = false): LineAmounts
    {
        $factor = BigDecimal::of($quantity)->multipliedBy(BigDecimal::one()->minus($discountRate));
        $gross = $unitPrice->multipliedBy($factor)->roundToCurrency();

        if ($inclusive) {
            $subtotal = $gross->dividedBy(BigDecimal::one()->plus($taxRate))->roundToCurrency();

            return new LineAmounts($subtotal, $gross->minus($subtotal), $gross);
        }

        $tax = $gross->multipliedBy($taxRate)->roundToCurrency();

        return new LineAmounts($gross, $tax, $gross->plus($tax));
    }

    /** @param list<LineAmounts> $lines */
    public function document(array $lines, string $currency): LineAmounts
    {
        $subtotal = Money::zero($currency);
        $tax = Money::zero($currency);

        foreach ($lines as $line) {
            $subtotal = $subtotal->plus($line->subtotal);
            $tax = $tax->plus($line->tax);
        }

        return new LineAmounts($subtotal, $tax, $subtotal->plus($tax));
    }
}
