<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Money;
use App\Domain\Reports\AccountingEquation;
use PHPUnit\Framework\TestCase;

final class AccountingEquationTest extends TestCase
{
    public function test_assets_equal_liabilities_plus_equity_including_profit(): void
    {
        // After the design-mockup invoice: AR 501,745 = VAT 65,445 + profit 436,300.
        $eq = new AccountingEquation(
            assets: Money::of('501745', 'ETB'),
            liabilities: Money::of('65445', 'ETB'),
            equity: Money::zero('ETB'),
            profitToDate: Money::of('436300', 'ETB'),
        );

        $this->assertTrue($eq->holds());
        $this->assertSame('501745.00', $eq->toArray()['liabilities_and_equity']);
    }

    public function test_a_gap_is_reported_not_hidden(): void
    {
        $eq = new AccountingEquation(Money::of('100', 'ETB'), Money::of('40', 'ETB'), Money::of('50', 'ETB'), Money::zero('ETB'));

        $this->assertFalse($eq->holds());
        $this->assertSame('10.00', $eq->toArray()['difference']);
    }
}
