<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' Amounts are (110): exclusive of tax, inclusive of tax, or out of scope of tax. An out-of-scope invoice
 * carries no tax whatever rates are sent, posts no tax line and is left off the VAT worksheet; a zero-rated one stays
 * on it. A credit note keeps its invoice's mode. Database rules: T135.
 */
final class TaxModeTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'vat_registered' => true, 'vat_number' => '0012345678'], $this->h)->assertOk();
    }

    private function rate(string $code): string
    {
        return $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }

    private function invoice(array $over, string $rate = 'VAT15'): array
    {
        return ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', ...$over,
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '2', 'unit_price' => '500', 'account_id' => $this->c['sales'], 'tax_rate_id' => $this->rate($rate)]]];
    }

    public function test_amounts_are_exclusive_inclusive_or_out_of_scope_of_tax(): void
    {
        $this->postJson('/api/v1/invoices', $this->invoice([]), $this->h)->assertCreated()
            ->assertJsonPath('tax_mode', 'exclusive')->assertJsonPath('tax_total', '150.00')->assertJsonPath('total', '1150.00');
        $this->postJson('/api/v1/invoices', $this->invoice(['tax_mode' => 'inclusive']), $this->h)->assertCreated()
            ->assertJsonPath('tax_mode', 'inclusive')->assertJsonPath('prices_include_tax', true)->assertJsonPath('total', '1000.00');
        $this->postJson('/api/v1/invoices', $this->invoice(['prices_include_tax' => true]), $this->h)->assertCreated()->assertJsonPath('tax_mode', 'inclusive');

        // Out of scope: the VAT rate sent is dropped; no tax, nothing inclusive.
        $id = $this->postJson('/api/v1/invoices', $this->invoice(['tax_mode' => 'out_of_scope', 'prices_include_tax' => true]), $this->h)->assertCreated()
            ->assertJsonPath('tax_mode', 'out_of_scope')->assertJsonPath('prices_include_tax', false)->assertJsonPath('tax_total', '0.00')->assertJsonPath('total', '1000.00')
            ->assertJsonPath('lines.0.tax_rate_id', null)->json('id');
        $this->postJson('/api/v1/invoices', $this->invoice(['tax_mode' => 'outside']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('tax_mode');

        // Posted: receivable and income only.
        $entry = $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk()->json('journal_entry_id');
        $this->assertSame(2, $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->count());

        // A credit note for it keeps its mode.
        $this->postJson("/api/v1/invoices/{$id}/credit-notes", ['issue_date' => '2026-11-14', 'notes' => 'One bag returned',
            'lines' => [['description' => 'Returned', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->c['sales'], 'tax_rate_id' => $this->rate('VAT15')]]], $this->h)
            ->assertCreated()->assertJsonPath('tax_mode', 'out_of_scope')->assertJsonPath('tax_total', '0.00');
    }

    public function test_the_vat_worksheet_leaves_out_of_scope_sales_off_and_keeps_zero_rated_ones(): void
    {
        $post = fn (array $body) => $this->postJson('/api/v1/invoices/' . $this->postJson('/api/v1/invoices', $body, $this->h)->assertCreated()->json('id') . '/approve', [], $this->h)->assertOk();
        $before = $this->getJson('/api/v1/tax/worksheet?kind=vat&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $post($this->invoice(['tax_mode' => 'out_of_scope']));
        $post($this->invoice([], 'VAT0'));

        $after = $this->getJson('/api/v1/tax/worksheet?kind=vat&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $taxable = fn ($s) => (float) $s->json('sales.taxable');
        $this->assertEqualsWithDelta($taxable($before) + 1000, $taxable($after), 0.001, 'the zero-rated sale only');
        $this->assertSame($before->json('sales.tax'), $after->json('sales.tax'));
        $this->assertContains('VAT0', collect($after->json('sales.rates'))->pluck('code')->all());
    }

    public function test_the_company_sets_how_new_documents_start(): void
    {
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->assertJsonPath('amounts_are', 'exclusive')->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'amounts_are' => 'inclusive'], $this->h)->assertOk()->assertJsonPath('amounts_are', 'inclusive');
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'amounts_are' => 'gross'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('amounts_are');
        $this->assertSame(1, $this->owner()->table('audit_log')->where('company_id', $this->c['company'])->whereRaw("after::text LIKE '%amounts_are%'")->count());
    }
}
