<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: goods returned on a credit note (058). They come back into the warehouse they left from at the cost they
 * left with, the entry moves that cost from cost of goods sold back onto inventory, never more than the invoice sold
 * comes back, and a price correction moves no stock.
 */
final class CreditNoteReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_returned_goods_come_back_at_the_cost_they_left_with_and_never_more_than_sold(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store, 2 Nov
        $h = ['X-Company-Id' => $c['company']];
        $line = fn (string $qty, string $price, ?string $item = null) => ['description' => 'Coffee bags', 'quantity' => $qty, 'unit_price' => $price,
            'account_id' => $c['sales'], 'item_id' => $item ?? $c['stock_item']];
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [$line('5', '120')]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $balance = fn () => $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])->first();
        $this->assertSame('15.00', number_format((float) $balance()->quantity, 2, '.', ''));

        $credit = fn (array $lines, bool $restock = true) => $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", [
            'issue_date' => '2026-11-06', 'notes' => 'Bags returned, damaged', 'lines' => $lines, 'restock' => $restock], $h);

        // Two bags back: in at 50.00 each, and the credit note's entry moves 100.00 from cost of goods sold to inventory.
        $back = $credit([$line('2', '120')])->assertCreated()->assertJsonPath('restock', true)->json('id');
        $entry = $this->postJson("/api/v1/invoices/{$back}/approve", [], $h)->assertOk()->json('journal_entry_id');
        $this->assertSame(['17.00', '850.00'], [number_format((float) $balance()->quantity, 2, '.', ''), number_format((float) $balance()->value, 2, '.', '')]);
        $account = fn (string $key) => $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get();
        $this->assertSame('100.00', (string) $lines->firstWhere('account_id', $account('inventory'))->debit);
        $this->assertSame('100.00', (string) $lines->firstWhere('account_id', $account('cogs'))->credit);
        $move = $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $back])->sole();
        $this->assertSame(['sale_return', '100.00'], [$move->kind, (string) $move->amount]);

        // Four more cannot come back (only three are left of the five sold); nor an item the invoice did not sell.
        $tooMany = $credit([$line('4', '10')])->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$tooMany}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $cups = $this->postJson('/api/v1/items', ['name' => 'Paper cups', 'type' => 'inventory', 'unit' => 'box', 'purchase_price' => '30'], $h)->assertCreated()->json('id');
        $other = $credit([$line('1', '10', $cups)])->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$other}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame('17.00', number_format((float) $balance()->quantity, 2, '.', ''));

        // A price correction on the same lines moves no stock.
        $price = $credit([$line('3', '10')], false)->assertCreated()->assertJsonPath('restock', false)->json('id');
        $this->postJson("/api/v1/invoices/{$price}/approve", [], $h)->assertOk();
        $this->assertSame(0, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $price])->count());
        $this->assertSame('17.00', number_format((float) $balance()->quantity, 2, '.', ''));
    }
}
