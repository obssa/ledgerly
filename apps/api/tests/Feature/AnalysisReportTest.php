<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: profit by location, class or project, and sales and purchases by party, item or month. The fixture company's
 * November: an invoice of 1,000.00 (+50.00 levy) less a 100.00 credit note; a bill of 400.00 less a 40.00 supplier
 * credit, and a 115.00 expense.
 */
final class AnalysisReportTest extends TestCase
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
    }

    public function test_profit_by_location_reads_the_tagged_ledger_lines(): void
    {
        $shop = $this->postJson('/api/v1/dimensions', ['kind' => 'location', 'code' => 'PIASSA', 'name' => 'Piassa shop'], $this->h)->assertCreated()->json('id');
        $journal = fn (string $dr, string $cr, string $amount, ?string $location) => $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-10', 'memo' => 'Shop day', 'lines' => [
            ['account_id' => $dr, 'debit' => $amount, 'location_id' => $location], ['account_id' => $cr, 'credit' => $amount, 'location_id' => $location]]], $this->h)->assertCreated();
        $journal($this->c['bank'], $this->c['sales'], '1000', $shop);
        $journal($this->c['rent'], $this->c['bank'], '300', $shop);

        $r = $this->getJson('/api/v1/reports/profit-by-dimension?kind=location&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()->assertJsonPath('kind', 'location');
        $rows = collect($r->json('rows'))->keyBy('name');
        $this->assertSame(['1000.00', '0.00', '300.00', '700.00', '70.0'],
            [$rows['Piassa shop']['income'], $rows['Piassa shop']['cost_of_sales'], $rows['Piassa shop']['expenses'], $rows['Piassa shop']['profit'], $rows['Piassa shop']['margin']]);
        $this->assertArrayHasKey('Not tagged', $rows->all(), 'the documents carry no location');
        $this->assertSame($r->json('totals.profit'), (string) collect($r->json('rows'))->reduce(fn ($t, $row) => \Brick\Math\BigDecimal::of($t)->plus($row['profit']), '0'));
        $this->getJson('/api/v1/reports/profit-by-dimension?kind=project&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()->assertJsonPath('kind', 'project');
        $this->getJson('/api/v1/reports/profit-by-dimension?kind=region', $this->h)->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_sales_and_purchases_by_party_item_and_month(): void
    {
        // The fixture's credits are drafts: post them, so they are taken off.
        $this->postJson("/api/v1/invoices/{$this->c['credit_note']}/approve", [], $this->h)->assertOk();
        $this->postJson("/api/v1/bills/{$this->c['supplier_credit']}/approve", [], $this->h)->assertOk();
        $byCustomer = $this->getJson('/api/v1/reports/sales?group=contact&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $customer = collect($byCustomer->json('rows'))->firstWhere('key', $this->c['contact']);
        $this->assertSame(['900.00', '50.00', 2, '100.0'], [$customer['amount'], $customer['tax'], $customer['documents'], $customer['share']]);   // the draft is left out
        $this->assertSame('900.00', $byCustomer->json('totals.amount'));
        $byItem = $this->getJson('/api/v1/reports/sales?group=item&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $this->assertSame('No item (described lines)', $byItem->json('rows.0.label'));
        $this->getJson('/api/v1/reports/sales?group=month&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()
            ->assertJsonPath('rows.0.key', '2026-11')->assertJsonPath('rows.0.amount', '900.00');

        $purchases = $this->getJson('/api/v1/reports/purchases?group=contact&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()->assertJsonPath('side', 'purchases');
        $supplier = collect($purchases->json('rows'))->firstWhere('key', $this->c['supplier']);
        $this->assertSame('475.00', $supplier['amount']);   // 400 bill - 40 credit + 115 expense
        $this->getJson('/api/v1/reports/sales?group=warehouse', $this->h)->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->getJson('/api/v1/reports/purchases?group=salesperson', $this->h)->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->getJson('/api/v1/reports/sales?from=2026-12-01&to=2026-11-01', $this->h)->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
