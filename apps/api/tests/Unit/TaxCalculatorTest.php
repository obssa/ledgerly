<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Money;
use App\Domain\Tax\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    public function test_vat_exclusive_invoice_matches_the_design_mockup(): void
    {
        $calc = new TaxCalculator();
        $lines = [
            $calc->line(Money::of('1150', 'ETB'), '240', '0', '0.15'),
            $calc->line(Money::of('980', 'ETB'), '160', '0', '0.15'),
            $calc->line(Money::of('3500', 'ETB'), '1', '0', '0.15'),
        ];

        $doc = $calc->document($lines, 'ETB');

        $this->assertSame('436300.00', $doc->subtotal->toDecimalString());
        $this->assertSame('65445.00', $doc->tax->toDecimalString());
        $this->assertSame('501745.00', $doc->total->toDecimalString());
    }

    public function test_discount_is_applied_before_tax(): void
    {
        $line = (new TaxCalculator())->line(Money::of('100', 'KES'), '3', '0.10', '0.16');

        $this->assertSame('270.00', $line->subtotal->toDecimalString());
        $this->assertSame('43.20', $line->tax->toDecimalString());
        $this->assertSame('313.20', $line->total->toDecimalString());
    }

    public function test_tax_inclusive_prices_split_back_into_net_and_tax(): void
    {
        $line = (new TaxCalculator())->line(Money::of('115', 'ETB'), '1', '0', '0.15', inclusive: true);

        $this->assertSame('100.00', $line->subtotal->toDecimalString());
        $this->assertSame('15.00', $line->tax->toDecimalString());
        $this->assertSame('115.00', $line->total->toDecimalString());
    }

    public function test_inclusive_rounding_never_loses_a_cent(): void
    {
        $line = (new TaxCalculator())->line(Money::of('99.99', 'ETB'), '1', '0', '0.15', inclusive: true);

        $this->assertSame(
            $line->total->toDecimalString(),
            $line->subtotal->plus($line->tax)->toDecimalString(),
        );
    }

    public function test_every_currency_keeps_two_decimals(): void
    {
        $line = (new TaxCalculator())->line(Money::of('1999', 'UGX'), '3', '0', '0.18');

        $this->assertSame('5997.00', $line->subtotal->toDecimalString());
        $this->assertSame('1079.46', $line->tax->toDecimalString());
    }

    public function test_fractional_rates_keep_their_precision(): void
    {
        // 7.5% VAT on 199.99: 14.99925 rounds half-up to 15.00
        $line = (new TaxCalculator())->line(Money::of('199.99', 'NGN'), '1', '0', '0.075');

        $this->assertSame('15.00', $line->tax->toDecimalString());
        $this->assertSame('214.99', $line->total->toDecimalString());
    }
}
