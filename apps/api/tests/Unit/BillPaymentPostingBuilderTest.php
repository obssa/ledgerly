<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\Money;
use App\Domain\Purchases\BillPaymentPostingBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BillPaymentPostingBuilderTest extends TestCase
{
    public function test_paying_a_bill_with_tax_withheld_and_a_bank_fee(): void
    {
        // Bill 23,000.00 (20,000.00 + VAT). 3% of 20,000.00 is withheld (600.00); 22,400.00 is paid, and the bank takes 25.00.
        $draft = $this->build('ETB', '1', '22400', '25', '600', [['23000.00', '1']]);

        $this->assertSame([
            ['ap', '23000.00', '0.00'],
            ['fees', '25.00', '0.00'],
            ['bank', '0.00', '22425.00'],
            ['wht', '0.00', '600.00'],
        ], $this->lines($draft));
    }

    public function test_what_is_not_allocated_is_an_advance_to_the_supplier(): void
    {
        $draft = $this->build('ETB', '1', '2000', '0', '0', [['1500.00', '1']]);

        $this->assertSame([['ap', '1500.00', '0.00'], ['ap', '500.00', '0.00'], ['bank', '0.00', '2000.00']], $this->lines($draft));
    }

    public function test_a_foreign_currency_bill_is_cleared_at_its_own_rate_and_the_difference_booked(): void
    {
        // 1,000 USD billed at 130 (payable 130,000.00), paid at 135: costs 5,000.00 more, a loss.
        $loss = $this->build('USD', '135', '1000', '0', '0', [['1000.00', '130']]);
        $this->assertSame([['ap', '130000.00', '0.00'], ['bank', '0.00', '135000.00'], ['fx', '5000.00', '0.00']], $this->lines($loss));

        // Paid at 128: a gain of 2,000.00.
        $gain = $this->build('USD', '128', '1000', '0', '0', [['1000.00', '130']]);
        $this->assertSame([['ap', '130000.00', '0.00'], ['bank', '0.00', '128000.00'], ['fx', '0.00', '2000.00']], $this->lines($gain));
    }

    public function test_more_cannot_be_allocated_than_the_payment_settles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->build('ETB', '1', '1000', '0', '0', [['1000.01', '1']]);
    }

    /** @param list<array{0: string, 1: string}> $allocations [amount, bill rate] */
    private function build(string $currency, string $rate, string $amount, string $fee, string $wht, array $allocations): JournalDraft
    {
        return (new BillPaymentPostingBuilder())->paid(
            companyId: 'co', paymentId: 'pay', date: new DateTimeImmutable('2026-11-10'), baseCurrency: 'ETB', paymentRate: $rate,
            supplierId: 'sup', bankAccountId: 'bank', payableAccountId: 'ap', feeAccountId: 'fees', withholdingAccountId: 'wht', fxAccountId: 'fx',
            amount: Money::of($amount, $currency), fee: Money::of($fee, $currency), withholding: Money::of($wht, $currency),
            allocations: array_map(fn (array $a) => ['amount' => Money::of($a[0], $currency), 'bill_rate' => $a[1]], $allocations),
        );
    }

    /** @return list<array{0: string, 1: string, 2: string}> account, debit, credit */
    private function lines(JournalDraft $draft): array
    {
        return array_map(fn ($l) => [$l->accountId, $l->debit->toDecimalString(), $l->credit->toDecimalString()], $draft->lines());
    }
}
