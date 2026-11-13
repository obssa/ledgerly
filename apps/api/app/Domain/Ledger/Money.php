<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use InvalidArgumentException;

/**
 * An exact amount in one currency. Never a float.
 *
 * Product rule: every amount has exactly 2 decimal places (NUMERIC(20,2) in the database), rounded
 * half-up the moment it is created. Rates (tax, discount, exchange) are not Money and keep their precision.
 */
final readonly class Money
{
    public const STORAGE_SCALE = 2;

    private function __construct(
        public BigDecimal $amount,
        public string $currency,
    ) {
    }

    public static function of(BigNumber|int|string $amount, string $currency): self
    {
        $currency = Currency::normalise($currency);
        $decimal = BigDecimal::of($amount);

        if ($decimal->getScale() > self::STORAGE_SCALE) {
            $decimal = $decimal->toScale(self::STORAGE_SCALE, Rounding::halfUp());
        }

        return new self($decimal->toScale(self::STORAGE_SCALE), $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    /** Multiply by a quantity, price factor or tax rate, rounding half-up to storage scale. */
    public function multipliedBy(BigNumber|int|string $factor): self
    {
        return new self(
            $this->amount->multipliedBy($factor)->toScale(self::STORAGE_SCALE, Rounding::halfUp()),
            $this->currency,
        );
    }

    /** Divide (e.g. to strip tax from a tax-inclusive price), rounding half-up to storage scale. */
    public function dividedBy(BigNumber|int|string $divisor): self
    {
        return new self(
            $this->amount->dividedBy($divisor, self::STORAGE_SCALE, Rounding::halfUp()),
            $this->currency,
        );
    }

    /** Round to the number of decimals the currency uses on documents (still stored at scale 4). */
    public function roundToCurrency(): self
    {
        $decimals = Currency::decimals($this->currency);

        return new self(
            $this->amount->toScale($decimals, Rounding::halfUp())->toScale(self::STORAGE_SCALE),
            $this->currency,
        );
    }

    /** Convert to another currency at the given rate (1 unit of this = $rate units of target). */
    public function convertTo(string $currency, BigNumber|int|string $rate): self
    {
        return new self(
            $this->amount->multipliedBy($rate)->toScale(self::STORAGE_SCALE, Rounding::halfUp()),
            Currency::normalise($currency),
        );
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    /** Value for a NUMERIC(20,2) column, e.g. "501745.00". */
    public function toDecimalString(): string
    {
        return (string) $this->amount;
    }

    public function __toString(): string
    {
        return $this->currency . ' ' . $this->amount->toScale(Currency::decimals($this->currency), Rounding::halfUp());
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
