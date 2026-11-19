<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2 (081): a credit note brings back goods that left on a delivery note. Six bags leave on a note at 50.00 each
 * and the order's invoice bills them; two come back on a credit note at the 100.00 they left with (Dr Inventory, Cr Cost
 * of goods sold), never more than the invoice billed, and the last four take exactly what is left of the cost. The
 * inventory account always equals the stock.
 */
final class CreditNoteDeliveredReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_goods_delivered_on_a_note_come_back_on_a_credit_note(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $balance = fn (string $key) => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account($key)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stock = fn () => $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])
            ->selectRaw('quantity::numeric(18,2)::text AS q, value::numeric(20,2)::text AS v')->first();

        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Coffee bags', 'quantity' => '10', 'unit_price' => '120', 'account_id' => $c['sales'], 'item_id' => $c['stock_item']]]], $h)
            ->assertCreated()->json();
        $this->postJson("/api/v1/sales-orders/{$so['id']}/accept", [], $h)->assertOk();
        $this->postJson("/api/v1/sales-orders/{$so['id']}/deliver", ['delivered_on' => '2026-11-12', 'lines' => [['line_id' => $so['lines'][0]['id'], 'quantity' => '6']]], $h)
            ->assertCreated()->assertJsonPath('cost', '300.00');
        $invoice = $this->postJson("/api/v1/sales-orders/{$so['id']}/convert", ['to' => 'invoice'], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $this->assertSame(['14.00', '700.00'], [$stock()->q, $stock()->v]);
        $cogs = $balance('cogs');

        $on = (string) $this->owner()->table('documents')->where('id', $invoice)->value('issue_date');   // the invoice is dated the day it was made
        $credit = fn (string $qty, string $price = '120') => $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => substr($on, 0, 10), 'notes' => 'Bags returned',
            'restock' => true, 'lines' => [['description' => 'Coffee bags', 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $c['sales'], 'item_id' => $c['stock_item']]]], $h)
            ->assertCreated()->json('id');

        // Two bags back at the 50.00 each they left the note with.
        $first = $credit('2');
        $this->postJson("/api/v1/invoices/{$first}/approve", [], $h)->assertOk();
        $this->assertSame(['16.00', '800.00'], [$stock()->q, $stock()->v]);
        $this->assertSame(bcsub($cogs, '100.00', 2), $balance('cogs'));
        $this->assertSame(['sale_return', '100.00'], (fn ($m) => [$m->kind, (string) $m->amount])($this->owner()->table('stock_moves')->where('document_id', $first)->sole()));

        // Never more than the invoice billed: four are left of the six.
        $tooMany = $credit('5', '10');   // within what may still be credited, but more bags than are left
        $this->postJson("/api/v1/invoices/{$tooMany}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $rest = $credit('4');
        $this->postJson("/api/v1/invoices/{$rest}/approve", [], $h)->assertOk();
        $this->assertSame(['20.00', '1000.00'], [$stock()->q, $stock()->v]);
        $this->assertSame(bcsub($cogs, '300.00', 2), $balance('cogs'));
        $this->assertSame($balance('inventory'), (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])
            ->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v'));
    }
}
