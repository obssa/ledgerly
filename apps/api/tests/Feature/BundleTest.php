<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: bundles and kits (073). A gift box of 2 bags of coffee and a ribbon is sold as one, at its own price; it
 * holds no stock, and selling it takes its stock parts out at their cost (the ribbon, a non-stock item, moves nothing).
 * Voiding the sale brings them back. A bundle's parts are other items, set while it is unused and fixed once it is on a
 * document; it is never bought; another company never sees its parts.
 */
final class BundleTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_bundle_sold_takes_its_stock_parts_out(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $stock = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $cogs = fn () => (string) $this->owner()->table('journal_lines')->where('company_id', $c['company'])
            ->where('account_id', $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'cogs'])->value('id'))
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');

        $ribbon = $this->postJson('/api/v1/items', ['name' => 'Ribbon', 'type' => 'non_inventory'], $h)->assertCreated()->json('id');
        $box = $this->postJson('/api/v1/items', ['name' => 'Gift box', 'type' => 'bundle', 'sale_price' => '1500', 'income_account_id' => $c['sales']], $h)
            ->assertCreated()->assertJsonPath('type', 'bundle')->assertJsonPath('can.components', true)->json('id');

        // Parts: other items, never a bundle or itself, each once.
        $set = fn (array $parts, array $hh = []) => $this->putJson("/api/v1/items/{$box}/components", ['components' => $parts], $hh ?: $h);
        $set([['item_id' => $box, 'quantity' => '1']])->assertUnprocessable();
        $set([['item_id' => $c['stock_item'], 'quantity' => '1'], ['item_id' => $c['stock_item'], 'quantity' => '2']])->assertUnprocessable();
        $this->putJson("/api/v1/items/{$c['stock_item']}/components", ['components' => [['item_id' => $ribbon, 'quantity' => '1']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('components');
        $set([['item_id' => $c['stock_item'], 'quantity' => '2'], ['item_id' => $ribbon, 'quantity' => '1']])->assertOk()
            ->assertJsonCount(2, 'components')->assertJsonPath('components.0.name', 'Ribbon');

        // Never bought.
        $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'reference' => 'GB-1',
            'lines' => [['description' => 'Gift box', 'quantity' => '1', 'unit_price' => '900', 'account_id' => $c['rent'], 'item_id' => $box]]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');

        // A cash sale of 3 boxes: 6 bags leave at 50.00, 300.00 to cost of goods sold; the box itself holds no stock.
        $before = $cogs();
        $sale = $this->postJson('/api/v1/sales-receipts', ['contact_id' => $c['contact'], 'paid_from_account_id' => $c['bank'], 'payment_method' => 'cash',
            'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Gift box', 'quantity' => '3', 'unit_price' => '1500', 'account_id' => $c['sales'], 'item_id' => $box]]], $h)
            ->assertCreated()->assertJsonPath('total', '4500.00')->json('id');
        $this->assertSame('14.00', $stock()['on_hand']);
        $this->assertSame(bcadd($before, '300.00', 2), $cogs());
        $this->assertSame(0, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'item_id' => $box])->count());

        // Used now: its parts stay as they were.
        $this->getJson("/api/v1/items/{$box}", $h)->assertOk()->assertJsonPath('can.components', false);
        $set([['item_id' => $c['stock_item'], 'quantity' => '3']])->assertUnprocessable()->assertJsonValidationErrors('components');

        // An invoice for 1 box takes 2 more bags.
        $inv = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Gift box', 'quantity' => '1', 'unit_price' => '1500', 'account_id' => $c['sales'], 'item_id' => $box]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$inv}/approve", [], $h)->assertOk();
        $this->assertSame('12.00', $stock()['on_hand']);

        // Voided, the cash sale's bags come back.
        $this->postJson("/api/v1/sales-receipts/{$sale}/void", ['reason' => 'Rang up twice'], $h)->assertOk();
        $this->assertSame('18.00', $stock()['on_hand']);
        $this->assertSame(bcadd($before, '100.00', 2), $cogs());

        // Another company sees none of its parts and cannot change them.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/items/{$box}", $hb)->assertNotFound();
        $set([['item_id' => $b['stock_item'], 'quantity' => '1']], $hb)->assertNotFound();
        $this->assertSame(0, $this->owner()->table('item_components')->where('company_id', $b['company'])->count());
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $this->assertContains('components_set', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $box])->pluck('action')->all());
    }
}
