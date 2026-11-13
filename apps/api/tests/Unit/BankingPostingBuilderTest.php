<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Banking\BankingPostingBuilder;
use App\Domain\Ledger\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BankingPostingBuilderTest extends TestCase
{
    public function test_transfer_from_telebirr_to_bank_with_fee(): void
    {
        $draft = (new BankingPostingBuilder())->transfer(
            'co', new DateTimeImmutable('2026-10-31'), 'telebirr', 'cbe',
            Money::of('118640', 'ETB'), Money::of('25', 'ETB'), 'bank_charges',
        );

        [$in, $out, $fee, $feeOut] = $draft->lines();
        $this->assertSame('cbe', $in->accountId);
        $this->assertSame('118640.00', $in->debit->toDecimalString());
        $this->assertSame('telebirr', $out->accountId);
        $this->assertSame('25.00', $fee->debit->toDecimalString());
        $this->assertSame('118665.00', $draft->totalCredit()->toDecimalString());
    }

    public function test_transfer_to_the_same_account_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BankingPostingBuilder())->transfer('co', new DateTimeImmutable(), 'cbe', 'cbe', Money::of('1', 'ETB'));
    }

    public function test_deposit_groups_receipts_into_one_bank_line(): void
    {
        $draft = (new BankingPostingBuilder())->deposit(
            'co', new DateTimeImmutable('2026-10-29'), 'cbe', 'undeposited',
            [Money::of('8000', 'ETB'), Money::of('3500', 'ETB'), Money::of('500', 'ETB')],
            slipNumber: 'DS-0042',
        );

        [$bank, $undeposited] = $draft->lines();
        $this->assertCount(2, $draft->lines());
        $this->assertSame('12000.00', $bank->debit->toDecimalString());
        $this->assertSame('12000.00', $undeposited->credit->toDecimalString());
        $this->assertSame('deposit', $draft->sourceType);
    }

    public function test_deposit_fee_reduces_the_bank_line(): void
    {
        $draft = (new BankingPostingBuilder())->deposit(
            'co', new DateTimeImmutable('2026-10-29'), 'cbe', 'undeposited',
            [Money::of('1000', 'KES')], Money::of('30', 'KES'), 'bank_charges',
        );

        [$bank, $fee, $undeposited] = $draft->lines();
        $this->assertSame('970.00', $bank->debit->toDecimalString());
        $this->assertSame('30.00', $fee->debit->toDecimalString());
        $this->assertSame('1000.00', $undeposited->credit->toDecimalString());
    }
}
