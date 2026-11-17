<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 4 and 10: stock movement for a period, and stock ageing (slow-moving and dead stock) at a date. */
final class StockReportTest extends TestCase
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

        // Roasted coffee: 20 at 50.00 (2 Nov, the fixtures), 10 bought at 60.00 (8 Nov), 12 sold (9 Nov) at the average 53.33,
        // 3 moved to the van (10 Nov). Green beans: 5 at 100.00 (2 Nov), never sold.
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-08', 'currency' => 'ETB', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '10', 'unit_price' => '60', 'account_id' => $this->c['rent'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $this->h)->assertOk();
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09', 'currency' => 'ETB', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '12', 'unit_price' => '1150', 'account_id' => $this->c['sales'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $this->h)->assertOk();
        $this->c['van'] = $this->postJson('/api/v1/warehouses', ['name' => 'Van'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/transfers', ['item_id' => $this->c['stock_item'], 'from_warehouse_id' => $this->c['store'], 'to_warehouse_id' => $this->c['van'],
            'date' => '2026-11-10', 'quantity' => '3'], $this->h)->assertCreated();
        $this->c['beans'] = $this->postJson('/api/v1/items', ['name' => 'Green beans 1 kg', 'sku' => 'GB-1K', 'type' => 'inventory', 'unit' => 'bag'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $this->c['beans'], 'warehouse_id' => $this->c['store'], 'date' => '2026-11-02',
            'quantity' => '5', 'unit_cost' => '100', 'opening' => true, 'reason' => 'Opening count'], $this->h)->assertCreated();
    }

    public function test_movement_shows_what_came_in_and_went_out_and_agrees_with_the_valuation(): void
    {
        $r = $this->getJson('/api/v1/reports/stock-movement?from=2026-11-05&to=2026-11-30', $this->h)->assertOk();
        $coffee = collect($r->json('rows'))->firstWhere('item.id', $this->c['stock_item']);
        $this->assertSame(['20.00', '1000.00', '10.00', '600.00', '12.00', '640.00', '12.00', '18.00', '960.00'], [$coffee['opening_qty'], $coffee['opening_value'],
            $coffee['in_qty'], $coffee['in_value'], $coffee['out_qty'], $coffee['out_value'], $coffee['sold_qty'], $coffee['closing_qty'], $coffee['closing_value']],
            'across all warehouses the transfer to the van is neither in nor out');
        $beans = collect($r->json('rows'))->firstWhere('item.id', $this->c['beans']);
        $this->assertSame(['5.00', '500.00', '0.00', '5.00'], [$beans['opening_qty'], $beans['opening_value'], $beans['out_qty'], $beans['closing_qty']]);
        $r->assertJsonPath('totals.opening_value', '1500.00')->assertJsonPath('totals.closing_value', '1460.00');
        $this->getJson('/api/v1/reports/inventory-valuation?as_of=2026-11-30', $this->h)->assertJsonPath('stock_value', $r->json('totals.closing_value'));

        // One warehouse: the transfer is out of the store and into the van, at its cost (3 x 53.33).
        $store = $this->getJson("/api/v1/reports/stock-movement?from=2026-11-05&to=2026-11-30&warehouse_id={$this->c['store']}", $this->h)->assertOk()
            ->assertJsonPath('warehouse.name', 'Main store');
        $this->assertSame(['15.00', '15.00'], [collect($store->json('rows'))->firstWhere('item.id', $this->c['stock_item'])['out_qty'],
            collect($store->json('rows'))->firstWhere('item.id', $this->c['stock_item'])['closing_qty']]);
        $van = $this->getJson("/api/v1/reports/stock-movement?from=2026-11-05&to=2026-11-30&warehouse_id={$this->c['van']}", $this->h)->assertOk()->assertJsonCount(1, 'rows');
        $this->assertSame(['3.00', '160.00'], [$van->json('rows.0.in_qty'), $van->json('rows.0.closing_value')]);
        $this->assertSame('1460.00', (string) \Brick\Math\BigDecimal::of($store->json('totals.closing_value'))->plus($van->json('totals.closing_value')));

        // Before anything moved, nothing is listed; a period must end after it starts.
        $this->getJson('/api/v1/reports/stock-movement?from=2026-10-01&to=2026-10-31', $this->h)->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/v1/reports/stock-movement?from=2026-11-30&to=2026-11-01', $this->h)->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_ageing_bands_what_is_on_hand_by_days_since_it_last_sold(): void
    {
        $r = $this->getJson('/api/v1/reports/stock-ageing?as_of=2026-11-30', $this->h)->assertOk()->assertJsonPath('total_value', '1460.00');
        $coffee = collect($r->json('rows'))->firstWhere('item.id', $this->c['stock_item']);
        $this->assertSame(['18.00', '960.00', '2026-11-09', '2026-11-08', 21, '0-30'],
            [$coffee['quantity'], $coffee['value'], $coffee['last_sold_on'], $coffee['last_received_on'], $coffee['days_since_sale'], $coffee['band']]);
        $beans = collect($r->json('rows'))->firstWhere('item.id', $this->c['beans']);
        $this->assertSame([null, null, 'never'], [$beans['last_sold_on'], $beans['days_since_sale'], $beans['band']]);
        $this->assertSame(['0-30' => '960.00', '31-90' => '0.00', 'never' => '500.00'],
            collect($r->json('bands'))->only([0, 4, 1])->mapWithKeys(fn ($b) => [$b['band'] => $b['value']])->all());

        // Three months on, the same coffee is slow-moving; by June, dead.
        $this->assertSame('91-180', collect($this->getJson('/api/v1/reports/stock-ageing?as_of=2027-03-01', $this->h)->json('rows'))->firstWhere('item.id', $this->c['stock_item'])['band']);
        $this->assertSame('over-180', collect($this->getJson('/api/v1/reports/stock-ageing?as_of=2027-06-01', $this->h)->json('rows'))->firstWhere('item.id', $this->c['stock_item'])['band']);
        // The van holds 3 bags of coffee, last sold (from the store) on 9 Nov: sales are per warehouse, so the van's never sold.
        $this->getJson("/api/v1/reports/stock-ageing?as_of=2026-11-30&warehouse_id={$this->c['van']}", $this->h)->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.band', 'never')->assertJsonPath('rows.0.quantity', '3.00');
    }

    public function test_another_companys_warehouse_is_refused_and_nothing_of_it_is_shown(): void
    {
        $me = $this->lastUser;
        $other = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $them = $this->lastUser;
        $this->actingAs($me, 'sanctum');
        $this->getJson("/api/v1/reports/stock-ageing?warehouse_id={$other['store']}", $this->h)->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->getJson("/api/v1/reports/stock-movement?warehouse_id={$other['store']}", $this->h)->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->actingAs($them, 'sanctum');
        $this->getJson('/api/v1/reports/stock-movement?warehouse_id=' . Str::uuid(), ['X-Company-Id' => $other['company']])->assertUnprocessable();
        $ours = collect($this->getJson('/api/v1/reports/stock-ageing?as_of=2026-11-30', ['X-Company-Id' => $other['company']])->assertOk()->json('rows'))->pluck('item.id');
        $this->assertNotContains($this->c['beans'], $ours);
        $this->assertNotContains($this->c['stock_item'], $ours);
    }
}
