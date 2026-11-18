<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9c: the tax depreciation schedule beside the books (061). An asset depreciated straight line in the books and
 * at 25% reducing balance for tax shows, for the fiscal year, the tax written-down value and the book value side by
 * side; the tax rule can change after the books depreciated it (nothing posts); another company's assets are not listed.
 */
final class TaxDepreciationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_tax_schedule_runs_beside_the_books_and_posts_nothing(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // fiscal year July to June; the fixture's laptop
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $code) => $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => $code])->value('id');
        $asset = fn (array $over = []) => [...['code' => 'VAN-1', 'name' => 'Delivery van', 'asset_account_id' => $account('1500'), 'depreciation_account_id' => $account('1590'),
            'expense_account_id' => $account('6700'), 'acquired_on' => '2026-11-05', 'cost' => '12000', 'method' => 'straight_line', 'useful_life_months' => 12], ...$over];

        $this->postJson('/api/v1/fixed-assets', $asset(['tax_method' => 'reducing_balance']), $h)->assertUnprocessable()->assertJsonValidationErrors('tax_annual_rate');
        $this->postJson('/api/v1/fixed-assets', $asset(['tax_method' => 'reducing_balance', 'tax_annual_rate' => '0.25', 'tax_opening_depreciation' => '13000']), $h)
            ->assertUnprocessable()->assertJsonValidationErrors('tax_opening_depreciation');
        $van = $this->postJson('/api/v1/fixed-assets', $asset(['tax_method' => 'reducing_balance', 'tax_annual_rate' => '0.25']), $h)->assertCreated()
            ->assertJsonPath('tax.method', 'reducing_balance')->assertJsonPath('tax.annual_rate', '0.250000')->json('id');
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();

        // The books post December and January (1,000.00 each).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-15 12:00'));
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $h)->assertCreated();
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2027-01-31'], $h)->assertCreated();
        $before = $entries();

        // Tax, December to June at 25% a year on the falling value: 250.00, 244.79, 239.69, 234.70, 229.81, 225.02, 220.33.
        $year = $this->getJson('/api/v1/fixed-assets/tax-depreciation?year_end=2027-06-30', $h)->assertOk()->assertJsonPath('from', '2026-07-01');
        $row = collect($year->json('rows'))->firstWhere('code', 'VAN-1');
        $this->assertSame(['0.00', '12000.00', '1644.34', '10355.66'], [$row['tax_opening'], $row['tax_additions'], $row['tax_depreciation'], $row['tax_closing']]);
        $this->assertSame(['2000.00', '10000.00', '355.66'], [$row['book_depreciation'], $row['book_closing'], $row['difference']]);
        $this->assertTrue($row['tax_rule']['own']);
        $this->assertFalse(collect($year->json('rows'))->firstWhere('code', 'IT-001')['tax_rule']['own']);   // no tax rule: the book one
        $this->assertNotContains($b['fixed_asset'], array_column($year->json('rows'), 'id'));
        $this->assertSame($before, $entries(), 'the tax schedule posts nothing');

        // After the books depreciated it, the tax rule can still change (the book rule cannot).
        $this->putJson("/api/v1/fixed-assets/{$van}", $asset(['tax_method' => 'straight_line', 'tax_useful_life_months' => '48']), $h)->assertOk()
            ->assertJsonPath('tax.method', 'straight_line')->assertJsonPath('tax.useful_life_months', 48);
        $this->putJson("/api/v1/fixed-assets/{$van}", $asset(['useful_life_months' => 24]), $h)->assertUnprocessable();
        $row = collect($this->getJson('/api/v1/fixed-assets/tax-depreciation?year_end=2027-06-30', $h)->json('rows'))->firstWhere('code', 'VAN-1');
        $this->assertSame('1750.00', $row['tax_depreciation']);   // 12,000.00 / 48 = 250.00 a month, seven months

        $this->getJson('/api/v1/fixed-assets/tax-depreciation?year_end=2027-06-15', $h)->assertUnprocessable()->assertJsonValidationErrors('year_end');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'asset_updated', 'subject_id' => $van])->count());
    }
}
