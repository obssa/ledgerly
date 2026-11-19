<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3 (finished): a landed cost goes onto goods that came in on a goods received note. Six bags came in on a note at
 * the order's 40.00 and the order's bill billed them; a 120.00 freight bill is offered that bill and lands on the bags,
 * all still on hand, so their value rises by 120.00; the inventory account still equals the stock.
 */
final class LandedReceivedTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_landed_cost_goes_onto_goods_received_on_a_note(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $key = fn (string $k) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $k])->value('id');
        $expense = (string) $this->owner()->table('accounts')->where('company_id', $c['company'])->where('type', 'expense')->whereNull('system_key')->value('id');
        $value = fn () => (string) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])
            ->selectRaw('value::numeric(20,2)::text AS v')->value('v');
        $books = fn () => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $key('inventory')])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stockValue = fn () => (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v');

        $po = $this->postJson('/api/v1/purchase-orders', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Green beans, bag', 'quantity' => '6', 'unit_price' => '40', 'account_id' => $c['rent'], 'item_id' => $c['stock_item']]]], $h)->assertCreated()->json();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/issue", [], $h)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['received_on' => '2026-11-12', 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => '6']]], $h)->assertCreated();
        $goods = $this->postJson("/api/v1/purchase-orders/{$po['id']}/bill", [], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$goods}/approve", [], $h)->assertOk();
        $freight = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-12', 'reference' => 'FR-9', 'currency' => 'ETB',
            'lines' => [['description' => 'Freight from Djibouti', 'quantity' => '1', 'unit_price' => '120', 'account_id' => $expense]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$freight}/approve", [], $h)->assertOk();
        $before = $value();

        $this->getJson("/api/v1/bills/{$freight}/landed-costs", $h)->assertOk()->assertJsonFragment(['id' => $goods]);
        $this->postJson("/api/v1/bills/{$goods}/landed-costs", ['goods_bill_id' => $freight, 'method' => 'value'], $h)->assertUnprocessable();   // goods are not freight
        $this->postJson("/api/v1/bills/{$freight}/landed-costs", ['goods_bill_id' => $goods, 'method' => 'value'], $h)
            ->assertCreated()->assertJsonPath('amount', '120.00')->assertJsonPath('inventory', '120.00')->assertJsonPath('cost_of_sales', '0.00');
        $this->assertSame(bcadd($before, '120.00', 2), $value());
        $this->assertSame($stockValue(), $books(), 'the inventory account still equals the stock');
    }
}
