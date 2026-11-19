<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (073, finished): a credit note brings back a bundle's parts. Two gift boxes (2 bags each) sold on an invoice took
 * 4 bags out at 50.00; a credit note for one box that came back brings 2 bags back at the 100.00 they left with (Dr
 * Inventory, Cr Cost of goods sold), and no more boxes come back than parts are left to return.
 */
final class BundleReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_bundles_parts_come_back_on_a_credit_note(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $bags = fn () => (string) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])
            ->selectRaw('quantity::numeric(18,2)::text AS q')->value('q');
        $cogs = fn () => (string) $this->owner()->table('journal_lines')->where('company_id', $c['company'])
            ->where('account_id', $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'cogs'])->value('id'))
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $box = $this->postJson('/api/v1/items', ['name' => 'Gift box', 'type' => 'bundle', 'sale_price' => '1500', 'income_account_id' => $c['sales']], $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$box}/components", ['components' => [['item_id' => $c['stock_item'], 'quantity' => '2']]], $h)->assertOk();
        $line = fn (string $qty) => ['description' => 'Gift box', 'quantity' => $qty, 'unit_price' => '1500', 'account_id' => $c['sales'], 'item_id' => $box];

        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [$line('2')]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $this->assertSame('16.00', $bags());
        $before = $cogs();

        $credit = fn (string $qty) => $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'Gift box returned',
            'restock' => true, 'lines' => [[...$line($qty), 'unit_price' => '10']]], $h)->assertCreated()->json('id');
        $one = $credit('1');
        $this->postJson("/api/v1/invoices/{$one}/approve", [], $h)->assertOk();
        $this->assertSame('18.00', $bags());
        $this->assertSame(bcsub($before, '100.00', 2), $cogs());
        $this->assertSame(['sale_return', '100.00'], (fn ($m) => [$m->kind, (string) $m->amount])($this->owner()->table('stock_moves')->where('document_id', $one)->sole()));

        // Two more boxes would bring back 4 bags; only 2 are left to return.
        $two = $credit('2');
        $this->postJson("/api/v1/invoices/{$two}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame('18.00', $bags());
    }
}
