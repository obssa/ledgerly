<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (097): bundles delivered on delivery notes. A gift box of 2 bags and a ribbon is ordered three times; two
 * leave on a note, taking 4 bags out at 50.00 with their cost; the invoice bills the two boxes without moving the bags
 * again; a credit note brings 2 bags (one box) back at the cost they left with. A box of ribbon only is not delivered.
 * Voiding a note that is not invoiced brings its bags back. Another company sees none of it.
 */
final class BundleDeliveryTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_bundle_leaves_on_a_delivery_note_as_its_stock_parts(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $balance = fn (string $key) => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account($key)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stockValue = fn () => (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])
            ->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v');
        $bags = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);

        $ribbon = $this->postJson('/api/v1/items', ['name' => 'Ribbon', 'type' => 'non_inventory'], $h)->assertCreated()->json('id');
        $box = $this->postJson('/api/v1/items', ['name' => 'Gift box', 'type' => 'bundle', 'sale_price' => '1500', 'income_account_id' => $c['sales']], $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$box}/components", ['components' => [['item_id' => $c['stock_item'], 'quantity' => '2'], ['item_id' => $ribbon, 'quantity' => '1']]], $h)->assertOk();
        $plain = $this->postJson('/api/v1/items', ['name' => 'Ribbon box', 'type' => 'bundle', 'sale_price' => '100', 'income_account_id' => $c['sales']], $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$plain}/components", ['components' => [['item_id' => $ribbon, 'quantity' => '2']]], $h)->assertOk();

        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [
                ['description' => 'Gift box', 'quantity' => '3', 'unit_price' => '1500', 'account_id' => $c['sales'], 'item_id' => $box],
                ['description' => 'Ribbon box', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $c['sales'], 'item_id' => $plain],
            ]], $h)->assertCreated()->json();
        $id = $so['id'];
        [$boxes, $ribbons] = array_column($so['lines'], 'id');
        $this->postJson("/api/v1/sales-orders/{$id}/accept", [], $h)->assertOk();
        $this->assertSame(['20.00', '6.00'], [$bags()['on_hand'], $bags()['reserved']]);   // 3 boxes hold 6 bags
        $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertOk()->assertJsonPath("leaving.{$boxes}", '3.00')->assertJsonMissingPath("leaving.{$ribbons}");

        $deliver = fn (array $lines) => $this->postJson("/api/v1/sales-orders/{$id}/deliver", ['delivered_on' => '2026-11-12', 'lines' => $lines], $h);
        $deliver([['line_id' => $ribbons, 'quantity' => '1']])->assertUnprocessable()->assertJsonValidationErrors('lines');   // nothing of it is stock
        $deliver([['line_id' => $boxes, 'quantity' => '4']])->assertUnprocessable()->assertJsonValidationErrors('lines');

        // Two boxes leave: 4 bags at 50.00, the cost of goods sold recorded now.
        $cogs = $balance('cogs');
        $note = $deliver([['line_id' => $boxes, 'quantity' => '2']])->assertCreated()->assertJsonPath('cost', '200.00')
            ->assertJsonPath('lines.0.item_name', 'Gift box')->assertJsonPath('lines.0.quantity', '2.00')->json();
        $this->assertSame(['16.00', '2.00'], [$bags()['on_hand'], $bags()['reserved']]);
        $this->assertSame(bcadd($cogs, '200.00', 2), $balance('cogs'));
        $this->assertSame($stockValue(), $balance('inventory'));
        $this->assertSame(0, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'item_id' => $box])->count());
        $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertOk()->assertJsonPath("delivery.{$boxes}.billable", '2.00')->assertJsonPath("leaving.{$boxes}", '1.00');

        // The invoice bills the two boxes (and the ribbon box) without moving the bags or their cost again.
        $invoice = $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $boxes, 'quantity' => '2'], ['line_id' => $ribbons, 'quantity' => '1']]], $h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $this->assertSame('16.00', $bags()['on_hand']);
        $this->assertSame(bcadd($cogs, '200.00', 2), $balance('cogs'));
        $this->assertSame(1, $this->owner()->table('invoice_delivery_matches')->where(['company_id' => $c['company'], 'invoice_id' => $invoice])->count());
        $this->postJson("/api/v1/delivery-notes/{$note['id']}/void", [], $h)->assertUnprocessable()->assertJsonValidationErrors('delivery_note');

        // One box comes back on a credit note: its 2 bags at the 50.00 they left with; never more than the boxes billed.
        $on = substr((string) $this->owner()->table('documents')->where('id', $invoice)->value('issue_date'), 0, 10);
        $credit = fn (string $qty) => $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => $on, 'notes' => 'Box returned', 'restock' => true,
            'lines' => [['description' => 'Gift box', 'quantity' => $qty, 'unit_price' => '10', 'account_id' => $c['sales'], 'item_id' => $box]]], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/invoices/' . $credit('1') . '/approve', [], $h)->assertOk();
        $this->assertSame('18.00', $bags()['on_hand']);
        $this->assertSame(bcadd($cogs, '100.00', 2), $balance('cogs'));
        $this->postJson('/api/v1/invoices/' . $credit('2') . '/approve', [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame($stockValue(), $balance('inventory'));

        // The last box leaves on a note not invoiced; voided, its bags come back.
        $last = $deliver([['line_id' => $boxes, 'quantity' => '1']])->assertCreated()->assertJsonPath('cost', '100.00')->json('id');
        $this->assertSame('16.00', $bags()['on_hand']);
        $this->postJson("/api/v1/delivery-notes/{$last}/void", ['reason' => 'Not sent'], $h)->assertOk()->assertJsonPath('status', 'void');
        $this->assertSame('18.00', $bags()['on_hand']);
        $this->assertSame($stockValue(), $balance('inventory'));

        // Another company sees none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/delivery-notes/{$note['id']}", ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
