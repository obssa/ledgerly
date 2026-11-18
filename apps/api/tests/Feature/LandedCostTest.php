<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: landed costs (060). A freight bill is spread over the goods another bill brought in, by value: the share of
 * goods still on hand raises their value, the share of goods sold goes to cost of goods sold, the freight leaves the
 * expense it was posted to, a cost bill is never spread twice, and another company's bill is not found.
 */
final class LandedCostTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_freight_goes_onto_goods_on_hand_and_cost_of_goods_sold_for_what_was_sold(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $expense = (string) $this->owner()->table('accounts')->where('company_id', $c['company'])->where('type', 'expense')->whereNull('system_key')->value('id');
        $cups = $this->postJson('/api/v1/items', ['name' => 'Paper cups', 'type' => 'inventory', 'unit' => 'box'], $h)->assertCreated()->json('id');
        $bill = fn (array $lines, string $ref, ?string $warehouse = null) => $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05',
            'reference' => $ref, 'currency' => 'ETB', 'warehouse_id' => $warehouse, 'lines' => $lines], $h)->assertCreated()->json('id');
        $line = fn (string $desc, string $qty, string $price, ?string $item = null) => ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price,
            'account_id' => $expense, 'item_id' => $item];

        // Goods: 10 boxes of cups at 30.00 (300.00) and 4 bags at 50.00 (200.00). Then 5 boxes of cups are sold.
        $goods = $bill([$line('Cups', '10', '30', $cups), $line('Beans', '4', '50', $c['stock_item'])], 'G-1', $c['store']);
        $this->postJson("/api/v1/bills/{$goods}/approve", [], $h)->assertOk();
        $sale = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-06', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Cups', 'quantity' => '5', 'unit_price' => '60', 'account_id' => $c['sales'], 'item_id' => $cups]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$sale}/approve", [], $h)->assertOk();
        // The freight: 100.00 to an expense account.
        $freight = $bill([$line('Freight from Djibouti', '1', '100')], 'FR-7');
        $this->postJson("/api/v1/bills/{$freight}/approve", [], $h)->assertOk();

        $this->getJson("/api/v1/bills/{$freight}/landed-costs", $h)->assertOk()->assertJsonPath('left', '100.00')->assertJsonPath('candidates.0.id', $goods)
            ->assertJsonPath('can.apply', true);
        $this->postJson("/api/v1/bills/{$freight}/landed-costs", ['goods_bill_id' => $freight, 'method' => 'value'], $h)->assertUnprocessable();
        $this->postJson("/api/v1/bills/{$freight}/landed-costs", ['goods_bill_id' => $b['bill'], 'method' => 'value'], $h)->assertNotFound();   // company B's
        $this->postJson("/api/v1/bills/{$goods}/landed-costs", ['goods_bill_id' => $freight, 'method' => 'value'], $h)->assertUnprocessable();   // goods are not freight

        // By value: cups take 60.00 (half still on hand: 30.00 onto them, 30.00 to cost of goods sold), beans take 40.00.
        $applied = $this->postJson("/api/v1/bills/{$freight}/landed-costs", ['goods_bill_id' => $goods, 'method' => 'value', 'date' => '2026-11-07'], $h)
            ->assertCreated()->assertJsonPath('amount', '100.00')->assertJsonPath('inventory', '70.00')->assertJsonPath('cost_of_sales', '30.00')->json();
        $value = fn (string $item) => number_format((float) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $item, 'warehouse_id' => $c['store']])->value('value'), 2, '.', '');
        $this->assertSame('180.00', $value($cups));   // 5 boxes: 150.00 + 30.00
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $applied['journal_entry_id'])->get();
        $this->assertSame('70.00', (string) $lines->firstWhere('account_id', $account('inventory'))->debit);
        $this->assertSame('30.00', (string) $lines->firstWhere('account_id', $account('cogs'))->debit);
        $this->assertSame('100.00', (string) $lines->firstWhere('account_id', $expense)->credit);
        $books = (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account('inventory')])
            ->selectRaw('coalesce(sum(debit) - sum(credit), 0) AS b')->value('b');
        $stock = (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])->sum('value');
        $this->assertSame(0, bccomp($books, $stock, 2), 'the inventory account equals the stock');

        // Spread once: nothing is left.
        $this->postJson("/api/v1/bills/{$freight}/landed-costs", ['goods_bill_id' => $goods, 'method' => 'quantity'], $h)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->getJson("/api/v1/bills/{$freight}/landed-costs", $h)->assertJsonPath('left', '0.00')->assertJsonCount(1, 'spread');
        $this->getJson("/api/v1/bills/{$goods}/landed-costs", $h)->assertJsonCount(1, 'received')->assertJsonPath('received.0.cost_bill_number', $this->owner()->table('documents')->where('id', $freight)->value('number'));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'landed_cost_applied'])->count());
    }
}
