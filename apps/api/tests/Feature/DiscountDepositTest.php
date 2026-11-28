<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' discount and deposit (113). A discount before tax, shared over the lines, posted to Discounts given; the
 * VAT is on what is charged. A deposit is received when the invoice is posted, as a payment applied to it. Unit maths:
 * Unit\TaxCalculatorTest. Database rules: T138.
 */
final class DiscountDepositTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $vat;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'vat_registered' => true, 'vat_number' => '0012345678'], $this->h)->assertOk();
        $this->vat = $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => 'VAT15'])->value('id');
    }

    private function invoice(array $over, array $prices = ['600', '400']): array
    {
        return ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', ...$over,
            'lines' => array_map(fn (string $p) => ['description' => 'Roasted coffee', 'quantity' => '1', 'unit_price' => $p, 'account_id' => $this->c['sales'], 'tax_rate_id' => $this->vat], $prices)];
    }

    public function test_a_discount_before_tax_is_shared_over_the_lines_and_posted_to_discounts_given(): void
    {
        $saved = $this->postJson('/api/v1/invoices', $this->invoice(['discount_type' => 'percent', 'discount_value' => '10']), $this->h)->assertCreated()
            ->assertJsonPath('discount_total', '100.00')->assertJsonPath('subtotal', '900.00')->assertJsonPath('tax_total', '135.00')->assertJsonPath('total', '1035.00')
            ->assertJsonPath('lines.0.discount_share', '60.00')->assertJsonPath('lines.0.line_subtotal', '540.00')->assertJsonPath('lines.1.line_tax', '54.00');
        $this->postJson('/api/v1/invoices', $this->invoice(['discount_type' => 'percent', 'discount_value' => '120']), $this->h)->assertUnprocessable();
        $this->postJson('/api/v1/invoices', $this->invoice(['discount_type' => 'amount']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('discount_value');

        $entry = $this->postJson("/api/v1/invoices/{$saved->json('id')}/approve", [], $this->h)->assertOk()->json('journal_entry_id');
        $lines = $this->owner()->table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $entry)
            ->get(['a.code', 'a.system_key', 'l.debit', 'l.credit'])->keyBy(fn ($l) => $l->system_key ?? $l->code);
        $this->assertSame(['1035.00', '0.00'], [(string) $lines['accounts_receivable']->debit, (string) $lines['accounts_receivable']->credit]);
        $this->assertSame('1000.00', (string) $lines['sales']->credit, 'sales before the discount');
        $this->assertSame('100.00', (string) $lines['discounts_given']->debit);
        $this->assertSame('135.00', (string) $lines['vat_payable']->credit);
        $this->assertStringStartsWith('49', $lines['discounts_given']->code);   // the first free code from 4900

        // The VAT worksheet has what was charged.
        $sheet = $this->getJson('/api/v1/tax/worksheet?kind=vat&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $this->assertSame('900.00', collect($sheet->json('sales.documents'))->firstWhere('id', $saved->json('id'))['taxable']);

        // A bill takes no discount.
        $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'discount_type' => 'percent', 'discount_value' => '10',
            'lines' => [['description' => 'Sacks', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->c['rent']]]], $this->h)
            ->assertCreated()->assertJsonPath('discount_total', '0.00')->assertJsonPath('total', '100.00');
    }

    public function test_a_deposit_is_received_and_applied_when_the_invoice_is_posted(): void
    {
        $this->postJson('/api/v1/invoices', $this->invoice(['deposit_amount' => '2000', 'deposit_account_id' => $this->c['bank']]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('deposit_amount');
        $this->postJson('/api/v1/invoices', $this->invoice(['deposit_amount' => '100', 'deposit_account_id' => $this->c['sales']]), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('deposit_account_id');

        $id = $this->postJson('/api/v1/invoices', $this->invoice(['deposit_amount' => '500', 'deposit_account_id' => $this->c['bank']]), $this->h)->assertCreated()
            ->assertJsonPath('deposit_amount', '500.00')->assertJsonPath('total', '1150.00')->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk()->assertJsonPath('amount_due', '650.00')->assertJsonPath('status', 'part_paid');

        $payment = $this->owner()->table('payments')->where(['company_id' => $this->c['company'], 'deposit_document_id' => $id])->first();
        $this->assertNotNull($payment);
        $this->assertSame(['500.00', $this->c['bank']], [(string) $payment->amount, $payment->account_id]);
        $this->assertSame('500.00', (string) $this->owner()->table('payment_allocations')->where(['payment_id' => $payment->id, 'document_id' => $id])->value('amount'));
    }
}
