<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_keeps_exact_decimals_without_floats(): void
    {
        $sum = Money::of('0.1', 'ETB')->plus(Money::of('0.2', 'ETB'));

        $this->assertSame('0.30', $sum->toDecimalString());
    }

    public function test_it_rounds_half_up_to_two_decimals(): void
    {
        $this->assertSame('10.01', Money::of('10.005', 'ETB')->roundToCurrency()->toDecimalString());
        $this->assertSame('1234.50', Money::of('1234.5', 'UGX')->roundToCurrency()->toDecimalString());
        $this->assertSame('0.13', Money::of('0.125', 'ETB')->toDecimalString());
        $this->assertSame('-10.01', Money::of('-10.005', 'ETB')->roundToCurrency()->toDecimalString());
    }

    public function test_it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1, 'ETB')->plus(Money::of(1, 'KES'));
    }

    public function test_it_converts_between_currencies(): void
    {
        $etb = Money::of('1240', 'USD')->convertTo('ETB', '134.52500000');

        $this->assertSame('166811.00', $etb->toDecimalString());
        $this->assertSame('ETB', $etb->currency);
    }

    public function test_it_rejects_unknown_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1, 'XYZ');
    }

    public function test_it_formats_for_display(): void
    {
        $this->assertSame('ETB 501745.00', (string) Money::of('501745', 'ETB'));
    }
}
