<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the cash flow statement, changes in equity and the financial health pack for 2025 of a company converted on
 * 1 Jan 2025: 100,000 of capital in, a 10,000 cash sale, 3,500 of rent paid and 500 accrued, 24,000 of equipment bought,
 * a 20,000 loan, 2,000 of depreciation. Net profit 4,000; cash up 102,500.
 */
final class FinancialStatementTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->actingAs($this->createUser('Owner', 'owner@example.test'), 'sanctum');
        $company = $this->postJson('/api/v1/companies', ['legal_name' => 'Statement Trading', 'country_code' => 'ET', 'fiscal_year_start_month' => 1,
            'conversion_date' => '2025-01-01'])->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $company];
        $a = fn (string $code) => $this->owner()->table('accounts')->where(['company_id' => $company, 'code' => $code])->value('id');
        foreach ([
            ['2025-01-02', 'Capital paid in', '1100', '3000', '100000'],
            ['2025-03-10', 'Cash sale', '1100', '4000', '10000'],
            ['2025-06-30', 'Rent paid', '6100', '1100', '3500'],
            ['2025-07-01', 'Delivery van', '1500', '1100', '24000'],
            ['2025-08-01', 'Bank loan', '1100', '2500', '20000'],
            ['2025-12-31', 'Rent accrued', '6100', '2100', '500'],
            ['2025-12-31', 'Depreciation', '6700', '1590', '2000'],
        ] as [$date, $memo, $dr, $cr, $amount]) {
            $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => $memo, 'lines' => [
                ['account_id' => $a($dr), 'debit' => $amount], ['account_id' => $a($cr), 'credit' => $amount]]], $this->h)->assertCreated();
        }
    }

    public function test_the_cash_flow_statement_explains_the_change_in_cash(): void
    {
        $cf = $this->getJson('/api/v1/reports/cash-flow?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()
            ->assertJsonPath('net_profit', '4000.00')->assertJsonPath('balanced', true)
            ->assertJsonPath('opening_cash', '0.00')->assertJsonPath('closing_cash', '102500.00')->assertJsonPath('net_change', '102500.00');
        $this->assertSame(['operating' => '6500.00', 'investing' => '-24000.00', 'financing' => '120000.00'], $cf->json('totals'));
        $operating = collect($cf->json('sections.operating'))->keyBy('code');
        $this->assertSame(['2000.00', '500.00'], [$operating['1590']['amount'], $operating['2100']['amount']]);   // depreciation added back, the accrual
        $this->assertStringStartsWith('Depreciation charged', $operating['1590']['name']);
        $this->assertSame(['2500' => '20000.00', '3000' => '100000.00'], collect($cf->json('sections.financing'))->pluck('amount', 'code')->all());
    }

    public function test_equity_moves_by_the_capital_and_the_profit_and_a_close_changes_nothing(): void
    {
        $soce = $this->getJson('/api/v1/reports/changes-in-equity?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()
            ->assertJsonPath('opening', '0.00')->assertJsonPath('closing', '104000.00')->assertJsonPath('net_profit', '4000.00');
        $lines = collect($soce->json('lines'))->keyBy('name');
        $this->assertSame('100000.00', $lines['Share capital']['closing']);
        $this->assertSame('4000.00', $lines['Retained earnings and profit']['closing']);

        // Closing 2025 moves the profit to retained earnings: the statements of 2025 are the same, and 2026 opens with the equity.
        $year = collect($this->getJson('/api/v1/fiscal-years', $this->h)->assertOk()->json('data'))->firstWhere('starts_on', '2025-01-01');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/close", [], $this->h)->assertCreated();
        $this->getJson('/api/v1/reports/changes-in-equity?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()->assertJsonPath('closing', '104000.00');
        $this->getJson('/api/v1/reports/cash-flow?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()->assertJsonPath('net_profit', '4000.00')->assertJsonPath('balanced', true);
        $this->getJson('/api/v1/reports/changes-in-equity?from=2026-01-01&to=2026-06-30', $this->h)->assertOk()
            ->assertJsonPath('opening', '104000.00')->assertJsonPath('movement', '0.00');
    }

    public function test_the_financial_health_pack(): void
    {
        $h = $this->getJson('/api/v1/reports/financial-health?from=2025-01-01&to=2025-12-31', $this->h)->assertOk();
        $this->assertSame(['102500.00', '500.00', '102500.00'], [$h->json('figures.current_assets'), $h->json('figures.current_liabilities'), $h->json('figures.cash')]);
        $this->assertSame('205.00', $h->json('ratios.current_ratio'), 'the loan is not a current liability');
        $this->assertSame('205.00', $h->json('ratios.quick_ratio'));
        $this->assertSame(['100.0', '40.0'], [$h->json('ratios.gross_margin'), $h->json('ratios.net_margin')]);
        $this->assertNull($h->json('ratios.dso_days'), 'no credit sales: no DSO, not a division by zero');
        $this->assertNull($h->json('ratios.cash_runway_months'), 'cash did not fall in the last three months');
        $this->getJson('/api/v1/reports/financial-health?from=2025-12-31&to=2025-01-01', $this->h)->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
