<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Ledger\Money;
use App\Domain\Ledger\Rounding;
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

    /**
     * QuickBooks' discount before tax (113): a percentage of the lines' subtotal or an amount (never more than it), shared
     * out over the positive lines in proportion to their amounts, the last one taking the rounding. Each line's tax is
     * worked out again on what is left of it. The same rule as the web app's discountedAmounts (lib/decimal.ts).
     *
     * @param list<LineAmounts> $lines the lines as calculate() made them
     * @param list<string>      $rates each line's tax rate, 0..1
     * @return array{lines: list<LineAmounts>, shares: list<Money>, discount: Money}
     */
    public function discount(array $lines, array $rates, ?string $type, ?string $value, string $currency): array
    {
        $zero = Money::zero($currency);
        $base = array_reduce($lines, fn (BigDecimal $t, LineAmounts $l) => $l->subtotal->isNegative() ? $t : $t->plus($l->subtotal->amount), BigDecimal::zero());
        $none = ['lines' => $lines, 'shares' => array_fill(0, count($lines), $zero), 'discount' => $zero];
        if ($type === null || $value === null || $base->isZero()) {
            return $none;
        }
        $wanted = $type === 'percent'
            ? $base->multipliedBy($value)->dividedBy(100, 2, Rounding::halfUp())
            : BigDecimal::of($value)->toScale(2, Rounding::halfUp());
        $discount = $wanted->isGreaterThan($base) ? $base->toScale(2) : $wanted;
        if ($discount->isZero()) {
            return $none;
        }

        $positive = array_keys(array_filter($lines, fn (LineAmounts $l) => ! $l->subtotal->isNegative() && ! $l->subtotal->isZero()));
        $last = end($positive);
        $given = BigDecimal::zero();
        $shares = [];
        $out = [];
        foreach ($lines as $i => $l) {
            $share = ! in_array($i, $positive, true) ? BigDecimal::zero()
                : ($i === $last ? $discount->minus($given) : $discount->multipliedBy($l->subtotal->amount)->dividedBy($base, 2, Rounding::halfUp()));
            $given = $given->plus($share);
            $net = $l->subtotal->minus(Money::of($share, $currency));
            $tax = $net->multipliedBy($rates[$i] ?? '0')->roundToCurrency();
            $shares[] = Money::of($share, $currency);
            $out[] = new LineAmounts($net, $tax, $net->plus($tax));
        }

        return ['lines' => $out, 'shares' => $shares, 'discount' => Money::of($discount, $currency)];
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
