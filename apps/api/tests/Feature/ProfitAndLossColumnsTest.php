<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the profit and loss with QuickBooks' "Display columns by". The columns add up to the one-column report,
 * every column a period or a customer of this company only.
 */
final class ProfitAndLossColumnsTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_columns_add_up_to_the_profit_and_loss(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $pl = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-10-01&to=2026-12-31', $h)->assertOk();

        // By month: October, November, December; the sale of 2 November is in the second column.
        $months = $this->getJson('/api/v1/reports/profit-and-loss/columns?' . http_build_query(['from' => '2026-10-01', 'to' => '2026-12-31', 'by' => 'period',
            'starts' => '2026-10-01,2026-11-01,2026-12-01']), $h)->assertOk()
            ->assertJsonPath('columns.1', ['key' => '2', 'label' => '2026-11-01']);
        $this->assertSame($pl->json('totals.net_profit.current'), $months->json('totals.net_profit.total'));
        $this->assertSame($pl->json('totals.income.current'), $months->json('totals.income.total'));
        $this->assertSame('0.00', $months->json('totals.income.values.0'), 'nothing was sold in October');
        $this->assertSame($months->json('totals.income.total'), $months->json('totals.income.values.1'));
        // The same accounts (the one-column report gives codes as numbers, the columns as text).
        $codes = fn ($rows) => collect($rows)->map(fn ($r) => (string) $r['code'])->sort()->values()->all();
        $this->assertSame($codes($pl->json('sections.income')), $codes($months->json('sections.income')), 'the same accounts');

        // By customer: the fixture's customer is a column, and the columns add up the same.
        $byContact = $this->getJson('/api/v1/reports/profit-and-loss/columns?from=2026-10-01&to=2026-12-31&by=contact', $h)->assertOk();
        $this->assertContains('Abyssinia Coffee customer', collect($byContact->json('columns'))->pluck('label')->all());
        $this->assertSame($pl->json('totals.net_profit.current'), $byContact->json('totals.net_profit.total'));
        $this->getJson('/api/v1/reports/profit-and-loss/columns?from=2026-10-01&to=2026-12-31&by=location', $h)->assertOk()
            ->assertJsonPath('totals.net_profit.total', $pl->json('totals.net_profit.current'));
    }

    public function test_what_is_refused_and_another_companys_customers_never_show(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $a = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $userA = $this->lastUser;
        $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($userA, 'sanctum');
        $h = ['X-Company-Id' => $a['company']];
        $url = fn (array $q) => '/api/v1/reports/profit-and-loss/columns?' . http_build_query(['from' => '2026-10-01', 'to' => '2026-12-31', ...$q]);

        $labels = collect($this->getJson($url(['by' => 'contact']), $h)->assertOk()->json('columns'))->pluck('label')->all();
        $this->assertNotContains('Harar Traders customer', $labels);

        $this->getJson($url(['by' => 'account; DROP']), $h)->assertUnprocessable()->assertJsonValidationErrors('by');
        $this->getJson($url(['by' => 'period']), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
        $this->getJson($url(['by' => 'period', 'starts' => '2026-11-01,2026-10-01']), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
        $this->getJson($url(['by' => 'period', 'starts' => '2026-10-01,2027-02-01']), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
        $this->getJson($url(['by' => 'period', 'starts' => '2026-10-01,2026-02-31']), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
        $this->getJson($url(['by' => 'period', 'starts' => "2026-10-01');DROP"]), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
        $days = array_map(fn (int $i) => (new \DateTimeImmutable('2026-10-01'))->modify("+{$i} days")->format('Y-m-d'), range(0, 61));
        $this->getJson($url(['by' => 'period', 'starts' => implode(',', $days)]), $h)->assertUnprocessable()->assertJsonValidationErrors('starts');
    }
}
