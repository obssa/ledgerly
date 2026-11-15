<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\Money;
use App\Domain\Sales\PaymentPostingBuilder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentPostingBuilderTest extends TestCase
{
    public function test_a_payment_with_tax_withheld_and_a_bank_fee_settles_the_whole_invoice(): void
    {
        // Invoice 1,150.00. The customer withholds 3% (34.50) and pays 1,115.50; the bank keeps a 10.00 fee.
        $draft = $this->build('ETB', '1', '1115.50', '10', '34.50', [['1150.00', '1']]);

        $this->assertSame([
            ['bank', '1105.50', '0.00'],
            ['fees', '10.00', '0.00'],
            ['wht', '34.50', '0.00'],
            ['ar', '0.00', '1150.00'],
        ], $this->lines($draft));
    }

    public function test_what_is_not_allocated_stays_as_the_customers_credit(): void
    {
        $draft = $this->build('ETB', '1', '2000', '0', '0', [['1500.00', '1']]);

        $this->assertSame([['bank', '2000.00', '0.00'], ['ar', '0.00', '1500.00'], ['ar', '0.00', '500.00']], $this->lines($draft));
    }

    public function test_a_foreign_currency_payment_clears_each_invoice_at_its_own_rate_and_books_the_difference(): void
    {
        // 1,000 USD invoiced at 130 (receivable 130,000.00), paid when the rate is 135: a gain of 5,000.00.
        $gain = $this->build('USD', '135', '1000', '0', '0', [['1000.00', '130']]);
        $this->assertSame([['bank', '135000.00', '0.00'], ['ar', '0.00', '130000.00'], ['fx', '0.00', '5000.00']], $this->lines($gain));

        // Paid at 128: a loss of 2,000.00.
        $loss = $this->build('USD', '128', '1000', '0', '0', [['1000.00', '130']]);
        $this->assertSame([['bank', '128000.00', '0.00'], ['ar', '0.00', '130000.00'], ['fx', '2000.00', '0.00']], $this->lines($loss));
    }

    public function test_more_cannot_be_allocated_than_the_payment_settles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->build('ETB', '1', '1000', '0', '0', [['1000.01', '1']]);
    }

    public function test_the_fee_cannot_be_more_than_what_was_received(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->build('ETB', '1', '10', '11', '0', []);
    }

    /** @param list<array{0: string, 1: string}> $allocations [amount, invoice rate] */
    private function build(string $currency, string $rate, string $amount, string $fee, string $wht, array $allocations): JournalDraft
    {
        return (new PaymentPostingBuilder())->received(
            companyId: 'co', paymentId: 'pay', date: new DateTimeImmutable('2026-11-10'), baseCurrency: 'ETB', paymentRate: $rate,
            customerId: 'cust', bankAccountId: 'bank', receivableAccountId: 'ar', feeAccountId: 'fees', withholdingAccountId: 'wht', fxAccountId: 'fx',
            amount: Money::of($amount, $currency), fee: Money::of($fee, $currency), withholding: Money::of($wht, $currency),
            allocations: array_map(fn (array $a) => ['amount' => Money::of($a[0], $currency), 'invoice_rate' => $a[1]], $allocations),
        );
    }

    /** @return list<array{0: string, 1: string, 2: string}> account, debit, credit */
    private function lines(JournalDraft $draft): array
    {
        return array_map(fn ($l) => [$l->accountId, $l->debit->toDecimalString(), $l->credit->toDecimalString()], $draft->lines());
    }
}
