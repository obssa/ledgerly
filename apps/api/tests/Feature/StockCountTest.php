<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 4: a stock count brings each item's quantity in a warehouse to what was counted, at cost, in one entry. */
final class StockCountTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_count_adjusts_shortages_and_surpluses_at_cost_and_posts_the_net_once(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store, 2 Nov
        $h = ['X-Company-Id' => $c['company']];
        $cups = $this->postJson('/api/v1/items', ['name' => 'Paper cups', 'sku' => 'CUP-1', 'type' => 'inventory', 'unit' => 'box', 'purchase_price' => '30'], $h)
            ->assertCreated()->json('id');
        $count = fn (array $lines, string $date = '2026-11-15') => $this->postJson('/api/v1/stock/counts',
            ['warehouse_id' => $c['store'], 'date' => $date, 'counted' => $lines, 'note' => 'Month-end count'], $h);

        $count([['item_id' => $b['stock_item'], 'quantity' => '1']])->assertUnprocessable()->assertJsonValidationErrors('counted.0.item_id');
        $count([['item_id' => $c['stock_item'], 'quantity' => '20']], '2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('date');   // moves after it

        // Two bags short (2 x 50.00 out), five boxes of cups found (5 x 30.00 in, at the purchase price: none on hand): 50.00 net in.
        $done = $count([['item_id' => $c['stock_item'], 'quantity' => '18'], ['item_id' => $cups, 'quantity' => '5']])->assertCreated()
            ->assertJsonPath('net', '50.00')->assertJsonPath('differences.0.value', '-100.00')->assertJsonPath('differences.1.value', '150.00');
        $balance = fn (string $item) => $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $item, 'warehouse_id' => $c['store']])->first();
        $this->assertSame(['18.00', '900.00'], [number_format((float) $balance($c['stock_item'])->quantity, 2, '.', ''), number_format((float) $balance($c['stock_item'])->value, 2, '.', '')]);
        $this->assertSame('150.00', number_format((float) $balance($cups)->value, 2, '.', ''));

        $lines = $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'journal_entry_id' => $done->json('journal_entry_id')])->get();
        $inventory = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');
        $this->assertSame('50.00', (string) $lines->firstWhere('account_id', $inventory)->debit);
        $this->assertCount(2, $lines);

        // Counting again what the books now say changes nothing and posts nothing.
        $count([['item_id' => $c['stock_item'], 'quantity' => '18']])->assertCreated()->assertJsonPath('net', '0.00')->assertJsonPath('journal_entry_id', null);
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'stock_counted'])->count());
    }
}
