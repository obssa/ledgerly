<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Money;
use App\Domain\Sales\InvoicePostingBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InvoicePostingBuilderTest extends TestCase
{
    public function test_invoice_debits_receivable_and_credits_income_and_vat(): void
    {
        $draft = (new InvoicePostingBuilder())->build(
            companyId: 'co', documentId: 'doc-1', documentNumber: 'INV-2019-0419',
            issueDate: new DateTimeImmutable('2026-11-02'), baseCurrency: 'ETB', currency: 'ETB', fxRate: '1',
            customerId: 'cust', receivableAccountId: 'ar',
            lines: [
                ['income_account_id' => 'sales', 'subtotal' => Money::of('276000', 'ETB'), 'tax' => Money::of('41400', 'ETB'), 'tax_account_id' => 'vat'],
                ['income_account_id' => 'sales', 'subtotal' => Money::of('156800', 'ETB'), 'tax' => Money::of('23520', 'ETB'), 'tax_account_id' => 'vat'],
                ['income_account_id' => 'delivery', 'subtotal' => Money::of('3500', 'ETB'), 'tax' => Money::of('525', 'ETB'), 'tax_account_id' => 'vat'],
            ],
        );

        $lines = $draft->lines();
        $this->assertCount(4, $lines); // AR + sales + VAT + delivery (grouped by account)
        $this->assertSame('ar', $lines[0]->accountId);
        $this->assertSame('501745.0000', $lines[0]->debit->toDecimalString());
        $this->assertSame('432800.0000', $lines[1]->credit->toDecimalString());
        $this->assertSame('65445.0000', $lines[2]->credit->toDecimalString());
        $this->assertSame('invoice', $draft->sourceType);
    }

    public function test_foreign_currency_invoice_stays_balanced_after_conversion(): void
    {
        // Three lines whose converted amounts each round: the receivable is the sum of rounded credits.
        $draft = (new InvoicePostingBuilder())->build(
            companyId: 'co', documentId: 'doc-2', documentNumber: 'INV-0401',
            issueDate: new DateTimeImmutable('2026-10-15'), baseCurrency: 'ETB', currency: 'USD', fxRate: '134.52333333',
            customerId: 'cust', receivableAccountId: 'ar',
            lines: [
                ['income_account_id' => 'export', 'subtotal' => Money::of('413.33', 'USD'), 'tax' => Money::zero('USD'), 'tax_account_id' => null],
                ['income_account_id' => 'freight', 'subtotal' => Money::of('413.33', 'USD'), 'tax' => Money::zero('USD'), 'tax_account_id' => null],
                ['income_account_id' => 'handling', 'subtotal' => Money::of('413.34', 'USD'), 'tax' => Money::zero('USD'), 'tax_account_id' => null],
            ],
        );

        $draft->assertBalanced();
        $this->assertSame('1240.0000', $draft->lines()[0]->amountTxn->toDecimalString());
        $this->assertSame('ETB', $draft->totalDebit()->currency);
    }

    public function test_credit_note_reverses_the_sides(): void
    {
        $draft = (new InvoicePostingBuilder())->build(
            companyId: 'co', documentId: 'cn-1', documentNumber: 'CN-0007',
            issueDate: new DateTimeImmutable('2026-11-02'), baseCurrency: 'KES', currency: 'KES', fxRate: '1',
            customerId: 'cust', receivableAccountId: 'ar',
            lines: [['income_account_id' => 'sales', 'subtotal' => Money::of('1000', 'KES'), 'tax' => Money::of('160', 'KES'), 'tax_account_id' => 'vat']],
            isCreditNote: true,
        );

        [$ar, $sales, $vat] = $draft->lines();
        $this->assertSame('1160.0000', $ar->credit->toDecimalString());
        $this->assertSame('1000.0000', $sales->debit->toDecimalString());
        $this->assertSame('160.0000', $vat->debit->toDecimalString());
        $this->assertSame('credit_note', $draft->sourceType);
    }
}
