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

    public function test_birr_to_dollars_values_both_sides_at_the_days_rate_and_books_the_margin(): void
    {
        $draft = (new BankingPostingBuilder())->currencyTransfer(
            'co', new DateTimeImmutable('2026-11-10'), 'ETB', 'cbe', 'cbe-usd',
            Money::of('155000', 'ETB'), Money::of('1000', 'USD'), '154.50', 'fx', Money::of('50', 'ETB'), 'bank_charges',
        );

        [$in, $out, $fx, $fee] = $draft->lines();
        $this->assertSame(['cbe-usd', '154500.00', '1000.00'], [$in->accountId, $in->debit->toDecimalString(), $in->amountTxn->toDecimalString()]);
        $this->assertSame(['cbe', '155000.00', '-1003.24'], [$out->accountId, $out->credit->toDecimalString(), $out->amountTxn->toDecimalString()]);
        $this->assertSame(['fx', '500.00'], [$fx->accountId, $fx->debit->toDecimalString()]);
        $this->assertSame(['bank_charges', '50.00'], [$fee->accountId, $fee->debit->toDecimalString()]);
        $this->assertSame('USD', $draft->currency);
        $this->assertSame('155050.00', $draft->totalCredit()->toDecimalString());
    }

    public function test_dollars_within_dollars_have_no_exchange_difference_and_the_fee_is_in_dollars(): void
    {
        $draft = (new BankingPostingBuilder())->currencyTransfer(
            'co', new DateTimeImmutable('2026-11-10'), 'ETB', 'usd-1', 'usd-2',
            Money::of('100', 'USD'), Money::of('100', 'USD'), '154.50', 'fx', Money::of('2', 'USD'), 'bank_charges',
        );
        $this->assertCount(4, $draft->lines());
        $this->assertSame(['15450.00', '15450.00', '309.00'], [$draft->lines()[0]->debit->toDecimalString(), $draft->lines()[1]->credit->toDecimalString(), $draft->lines()[2]->debit->toDecimalString()]);
    }

    public function test_two_foreign_currencies_or_unequal_amounts_in_one_currency_are_refused(): void
    {
        $builder = new BankingPostingBuilder();
        foreach ([[Money::of('100', 'USD'), Money::of('90', 'EUR')], [Money::of('100', 'USD'), Money::of('99', 'USD')]] as [$sent, $received]) {
            try {
                $builder->currencyTransfer('co', new DateTimeImmutable(), 'ETB', 'a', 'b', $sent, $received, '154.50', 'fx');
                $this->fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
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
