<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: units of measure (072). An item is counted in its own unit; it may be bought and sold in others, each a
 * fixed number of its own (the fixture's coffee is counted in bags and has a carton of 12). A line in a carton prices
 * the carton and moves 12 bags of stock per carton, at the bag's cost. An order made in cartons is reserved, delivered
 * and invoiced in cartons. A unit keeps its size; an archived one is not chosen again; it is never another item's, and
 * another company never sees it.
 */
final class UnitsOfMeasureTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_documents_in_another_unit_move_stock_in_the_items_own_unit(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $carton = $c['item_unit'];
        $item = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $inventory = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');

        // Units: not the item's own, not twice, of a size above zero.
        $this->postJson("/api/v1/items/{$c['stock_item']}/units", ['name' => 'Bag', 'factor' => '1'], $h)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/items/{$c['stock_item']}/units", ['name' => 'carton', 'factor' => '6'], $h)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/items/{$c['stock_item']}/units", ['name' => 'Pallet', 'factor' => '0'], $h)->assertUnprocessable()->assertJsonValidationErrors('factor');
        $pallet = collect($this->postJson("/api/v1/items/{$c['stock_item']}/units", ['name' => 'Pallet', 'factor' => '240'], $h)->assertCreated()
            ->json('units'))->firstWhere('name', 'Pallet')['id'];
        $this->assertSame(['Carton', 'Pallet'], array_column($item()['units'], 'name'));

        // A bill for 2 cartons at 600.00 (50.00 a bag): 24 bags come in at 1,200.00.
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'reference' => 'C-2',
            'warehouse_id' => $c['store'], 'lines' => [['description' => 'Coffee, carton of 12', 'quantity' => '2', 'unit_price' => '600', 'account_id' => $c['rent'],
                'item_id' => $c['stock_item'], 'unit_id' => $carton]]], $h)->assertCreated()
            ->assertJsonPath('lines.0.unit_name', 'Carton')->assertJsonPath('lines.0.unit_factor', '12.000000')->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $this->assertSame(['44.00', '2200.00'], [$item()['on_hand'], $item()['value']]);

        // An invoice for 1 carton: 12 bags leave at the bag's 50.00.
        $sale = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-11', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Coffee, carton of 12', 'quantity' => '1', 'unit_price' => '13800', 'account_id' => $c['sales'],
                'item_id' => $c['stock_item'], 'unit_id' => $carton]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$sale}/approve", [], $h)->assertOk();
        $this->assertSame(['32.00', '1600.00'], [$item()['on_hand'], $item()['value']]);
        $out = (string) $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $sale])->value('quantity');
        $this->assertSame('-12.00', $out);

        // Another item's unit, or an archived one, is refused.
        $line = fn (array $over) => ['contact_id' => $c['contact'], 'issue_date' => '2026-11-11', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [[...['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $c['sales']], ...$over]]];
        $this->postJson('/api/v1/invoices', $line(['item_id' => $c['item'], 'unit_id' => $carton]), $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson("/api/v1/items/{$c['stock_item']}/units/{$pallet}/archive", [], $h)->assertOk();
        $this->postJson('/api/v1/invoices', $line(['item_id' => $c['stock_item'], 'unit_id' => $pallet]), $h)->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_id');
        $this->assertSame(['Carton'], array_column($item()['units'], 'name'));

        // An order in cartons: reserved in bags, delivered and invoiced in cartons.
        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Coffee, carton of 12', 'quantity' => '2', 'unit_price' => '13800', 'account_id' => $c['sales'],
                'item_id' => $c['stock_item'], 'unit_id' => $carton]]], $h)->assertCreated()->json();
        $this->postJson("/api/v1/sales-orders/{$so['id']}/accept", [], $h)->assertOk();
        $this->assertSame('24.00', $item()['reserved']);
        $this->postJson("/api/v1/sales-orders/{$so['id']}/deliver", ['delivered_on' => '2026-11-12', 'lines' => [['line_id' => $so['lines'][0]['id'], 'quantity' => '1']]], $h)
            ->assertCreated()->assertJsonPath('cost', '600.00');
        $this->assertSame(['20.00', '12.00'], [$item()['on_hand'], $item()['reserved']]);
        $made = $this->postJson("/api/v1/sales-orders/{$so['id']}/convert", ['to' => 'invoice'], $h)->assertCreated()->json('id');
        $this->getJson("/api/v1/invoices/{$made}", $h)->assertOk()->assertJsonPath('lines.0.quantity', '1.00')->assertJsonPath('lines.0.unit_id', $carton);
        $this->postJson("/api/v1/invoices/{$made}/approve", [], $h)->assertOk();
        $this->assertSame('20.00', $item()['on_hand']);

        // The inventory account still equals the stock.
        $account = (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $inventory])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $this->assertSame($item()['value'], $account);

        // Another company neither sees the units nor uses them.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/items/{$c['stock_item']}/units", ['name' => 'Box', 'factor' => '6'], $hb)->assertNotFound();
        $this->postJson("/api/v1/items/{$c['stock_item']}/units/{$carton}/archive", [], $hb)->assertNotFound();
        $this->postJson('/api/v1/invoices', ['contact_id' => $b['contact'], 'issue_date' => '2026-11-11', 'currency' => 'ETB', 'warehouse_id' => $b['store'],
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $b['sales'], 'item_id' => $b['stock_item'], 'unit_id' => $carton]]], $hb)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_id');
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $c['stock_item']])->pluck('action')->all();
        $this->assertContains('unit_added', $audit);
        $this->assertContains('unit_archived', $audit);
    }
}
