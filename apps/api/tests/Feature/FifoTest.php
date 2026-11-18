<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: FIFO as an option per item (064). Bought at 10.00, then at 20.00, 15 sold: the invoice's cost of goods sold
 * is 200.00 from the oldest layers (the average would be 225.00), the 5 left are worth 100.00, and the inventory
 * account equals the stock. The costing is chosen before the first move and kept; a service is never FIFO.
 */
final class FifoTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_fifo_item_is_sold_from_its_oldest_purchases(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $expense = (string) $this->owner()->table('accounts')->where('company_id', $c['company'])->where('type', 'expense')->whereNull('system_key')->value('id');

        $this->postJson('/api/v1/items', ['name' => 'Roasting', 'type' => 'service', 'costing' => 'fifo'], $h)->assertUnprocessable()->assertJsonValidationErrors('costing');
        $beans = $this->postJson('/api/v1/items', ['name' => 'Sidamo green beans', 'type' => 'inventory', 'unit' => 'kg', 'costing' => 'fifo'], $h)
            ->assertCreated()->assertJsonPath('costing', 'fifo')->json('id');
        $buy = function (string $qty, string $price, string $date, string $ref) use ($c, $h, $beans, $expense) {
            $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => $date, 'reference' => $ref, 'currency' => 'ETB', 'warehouse_id' => $c['store'],
                'lines' => [['description' => 'Green beans', 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $expense, 'item_id' => $beans]]], $h)->assertCreated()->json('id');
            $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        };
        $buy('10', '10', '2026-11-03', 'GB-1');
        $buy('10', '20', '2026-11-04', 'GB-2');
        $this->putJson("/api/v1/items/{$beans}", ['costing' => 'average'], $h)->assertUnprocessable()->assertJsonValidationErrors('costing');

        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Green beans', 'quantity' => '15', 'unit_price' => '40', 'account_id' => $c['sales'], 'item_id' => $beans]]], $h)->assertCreated()->json('id');
        $entry = $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk()->json('journal_entry_id');
        $this->assertSame('200.00', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $account('cogs')])->value('debit'));

        $balance = $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $beans, 'warehouse_id' => $c['store']])->first();
        $this->assertSame(['5.00', '100.00'], [number_format((float) $balance->quantity, 2, '.', ''), number_format((float) $balance->value, 2, '.', '')]);
        $books = (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account('inventory')])
            ->selectRaw('coalesce(sum(debit) - sum(credit), 0) AS b')->value('b');
        $this->assertSame(0, bccomp($books, (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])->sum('value'), 2), 'the inventory account equals the stock');

        // Six more cannot leave: only five are on hand, and a FIFO item never goes below zero.
        $more = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-06', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Green beans', 'quantity' => '6', 'unit_price' => '40', 'account_id' => $c['sales'], 'item_id' => $beans]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$more}/approve", [], $h)->assertUnprocessable();
        $this->assertSame(['costing' => ['average', 'fifo']], ['costing' => [
            $this->owner()->table('items')->where('id', $c['stock_item'])->value('costing'), $this->owner()->table('items')->where('id', $beans)->value('costing')]]);
    }
}
