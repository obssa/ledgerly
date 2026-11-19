<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3 (088): a supplier credit sends back goods that came in on a goods received note. Six bags came in on a note
 * at the order's 40.00 and the order's bill billed them; two go back to the supplier on a credit, out of stock at the
 * average cost, never more than the bill billed, and the inventory account still equals the stock.
 */
final class ReceivedGoodsReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_goods_received_on_a_note_go_back_on_a_supplier_credit(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $key = fn (string $k) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $k])->value('id');
        $bags = fn () => (string) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])
            ->selectRaw('quantity::numeric(18,2)::text AS q')->value('q');
        $books = fn () => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $key('inventory')])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stockValue = fn () => (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v');

        $po = $this->postJson('/api/v1/purchase-orders', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Green beans, bag', 'quantity' => '10', 'unit_price' => '40', 'account_id' => $c['rent'], 'item_id' => $c['stock_item']]]], $h)
            ->assertCreated()->json();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/issue", [], $h)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/receive", ['received_on' => '2026-11-12', 'lines' => [['line_id' => $po['lines'][0]['id'], 'quantity' => '6']]], $h)
            ->assertCreated();
        $bill = $this->postJson("/api/v1/purchase-orders/{$po['id']}/bill", [], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $this->assertSame('26.00', $bags());

        $on = substr((string) $this->owner()->table('documents')->where('id', $bill)->value('issue_date'), 0, 10);   // the bill is dated the day it was made
        $credit = fn (string $qty, string $price) => $this->postJson("/api/v1/bills/{$bill}/credits", ['issue_date' => $on, 'notes' => 'Torn bags returned',
            'restock' => true, 'lines' => [['description' => 'Green beans, bag', 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $key('inventory'), 'item_id' => $c['stock_item']]]], $h)
            ->assertCreated()->json('id');

        $first = $credit('2', '40');
        $this->postJson("/api/v1/bills/{$first}/approve", [], $h)->assertOk();
        $this->assertSame('24.00', $bags());
        $this->assertSame('purchase_return', (string) $this->owner()->table('stock_moves')->where('document_id', $first)->value('kind'));
        $this->assertSame($stockValue(), $books(), 'the inventory account still equals the stock');

        // Never more than the bill billed: four are left of the six.
        $tooMany = $credit('5', '1');
        $this->postJson("/api/v1/bills/{$tooMany}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame('24.00', $bags());
    }
}
