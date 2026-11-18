<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 7: turnover tax, where a country pack or the company has TOT rates (052). The rate here is a test rate only:
 * no rate goes live without the country's tax adviser.
 */
final class TotReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tot_posts_to_its_own_account_and_is_reported_filed_and_settled_apart_from_vat(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->getJson('/api/v1/tax/worksheet?kind=tot&from=2026-11-01&to=2026-11-30', $h)->assertUnprocessable()->assertJsonValidationErrors('kind');

        $rate = $this->postJson('/api/v1/tax-rates', ['code' => 'TOTTEST', 'name' => 'Turnover tax (test rate)', 'kind' => 'tot', 'rate' => '0.02', 'effective_from' => '2026-07-08'], $h)
            ->assertCreated()->json('id');
        $tot = $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'tot_payable'])->first();
        $this->assertNotNull($tot, 'the TOT payable account comes with the first TOT rate');
        $this->assertSame(['liability', '2320'], [$tot->type, $tot->code]);

        $vatBefore = $this->getJson('/api/v1/tax/worksheet?kind=vat&from=2026-11-01&to=2026-11-30', $h)->assertOk()->json('output_tax');
        $sale = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $c['sales'], 'tax_rate_id' => $rate]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$sale}/approve", [], $h)->assertOk();
        $credited = (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $tot->id])->sum('credit');
        $this->assertSame('20.00', number_format((float) $credited, 2, '.', ''));

        $sheet = $this->getJson('/api/v1/tax/worksheet?kind=tot&from=2026-11-01&to=2026-11-30', $h)->assertOk()
            ->assertJsonPath('output_tax', '20.00')->assertJsonPath('input_tax', '0.00')->assertJsonPath('sales.tax', '20.00');
        $this->assertSame('TOTTEST', collect($sheet->json('sales.rates'))->firstWhere('tax', '20.00')['code']);
        $this->getJson('/api/v1/tax/worksheet?kind=vat&from=2026-11-01&to=2026-11-30', $h)->assertOk()->assertJsonPath('output_tax', $vatBefore);   // VAT is untouched

        // Filed after the period, then settled from the bank: TOT payable against the bank.
        Carbon::setTestNow(Carbon::parse('2026-12-05 09:00:00', 'UTC'));
        $id = $this->postJson('/api/v1/tax-returns', ['kind' => 'tot', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'filed_on' => '2026-12-04'], $h)
            ->assertCreated()->assertJsonPath('net', '20.00')->json('id');
        $settled = $this->postJson("/api/v1/tax-returns/{$id}/settle", ['date' => '2026-12-05', 'bank_account_id' => $c['bank']], $h)->assertOk();
        $lines = collect($this->getJson("/api/v1/journal-entries/{$settled->json('settlement.id')}", $h)->assertOk()->json('lines'));
        $this->assertSame('20.00', $lines->firstWhere('account_id', $tot->id)['debit']);
        $this->assertSame('20.00', $lines->firstWhere('account_id', $c['bank'])['credit']);
        // A VAT return for the same month is still its own.
        $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'filed_on' => '2026-12-04'], $h)->assertCreated();
    }
}
