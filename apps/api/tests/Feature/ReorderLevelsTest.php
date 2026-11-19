<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: minimum and maximum levels (075). A stock item at or below its reorder point, counting what is on order and
 * what open sales orders hold, is running low; the suggestion brings it back up to its maximum level, and becomes a
 * draft purchase order to its preferred supplier at its purchase price. Nothing posts. The maximum is never below the
 * reorder point, the supplier is the company's own, and another company never sees or orders its items.
 */
final class ReorderLevelsTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_running_low_suggests_an_order_up_to_the_maximum(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags on hand
        $h = ['X-Company-Id' => $c['company']];
        $item = $c['stock_item'];
        $low = fn () => collect($this->getJson('/api/v1/stock/reorder', $h)->assertOk()->json('data'))->firstWhere('item.id', $item);
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();

        $this->putJson("/api/v1/items/{$item}", ['reorder_point' => '30', 'max_level' => '25'], $h)->assertUnprocessable()->assertJsonValidationErrors('max_level');
        $this->putJson("/api/v1/items/{$item}", ['preferred_supplier_id' => $c['contact']], $h)->assertUnprocessable()->assertJsonValidationErrors('preferred_supplier_id');
        $this->putJson("/api/v1/items/{$item}", ['preferred_supplier_id' => $b['supplier']], $h)->assertUnprocessable()->assertJsonValidationErrors('preferred_supplier_id');
        $this->putJson("/api/v1/items/{$item}", ['reorder_point' => '30', 'max_level' => '100', 'purchase_price' => '48', 'preferred_supplier_id' => $c['supplier']], $h)
            ->assertOk()->assertJsonPath('max_level', '100.00');

        // 20 on hand: back up to 100 is 80.
        $this->assertSame(['20.00', '80.00'], [$low()['position'], $low()['suggested']]);
        $this->assertSame($c['supplier'], $low()['supplier']['id']);

        // A confirmed sales order holds 10; an issued purchase order brings 50.
        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Coffee', 'quantity' => '10', 'unit_price' => '120', 'account_id' => $c['sales'], 'item_id' => $item]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/sales-orders/{$so}/accept", [], $h)->assertOk();
        $this->assertSame(['10.00', '90.00'], [$low()['position'], $low()['suggested']]);
        $po = $this->postJson('/api/v1/purchase-orders', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Coffee', 'quantity' => '50', 'unit_price' => '48', 'account_id' => $c['rent'], 'item_id' => $item]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/purchase-orders/{$po}/issue", [], $h)->assertOk();
        $this->assertNull($low(), 'on hand 20 + on order 50 - held 10 = 60, above the reorder point of 30');

        // The order cancelled: 20 - 10 = 10 again, so 90 to reach 100, ordered as a draft purchase order to the supplier.
        $this->postJson("/api/v1/purchase-orders/{$po}/cancel", [], $h)->assertOk();
        $this->assertSame('90.00', $low()['suggested']);
        $before = $entries();
        $made = $this->postJson('/api/v1/stock/reorder/purchase-orders', ['warehouse_id' => $c['store'], 'lines' => [['item_id' => $item, 'quantity' => $low()['suggested']]]], $h)
            ->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.contact_id', $c['supplier'])->assertJsonPath('data.0.total', '4320.00')->json('data.0.id');
        $this->getJson("/api/v1/purchase-orders/{$made}", $h)->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('lines.0.quantity', '90.00')
            ->assertJsonPath('lines.0.unit_price', '48.00')->assertJsonPath('lines.0.item_id', $item);
        $this->assertSame($before, $entries(), 'a suggested order posts nothing');

        // An item with no preferred supplier is not ordered this way.
        $other = $this->postJson('/api/v1/items', ['name' => 'Filters', 'type' => 'inventory', 'reorder_point' => '5'], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/reorder/purchase-orders', ['warehouse_id' => $c['store'], 'lines' => [['item_id' => $other, 'quantity' => '5']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');

        // Another company neither sees the item running low nor orders it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertNull(collect($this->getJson('/api/v1/stock/reorder', $hb)->assertOk()->json('data'))->firstWhere('item.id', $item));
        $this->postJson('/api/v1/stock/reorder/purchase-orders', ['warehouse_id' => $b['store'], 'lines' => [['item_id' => $item, 'quantity' => '5']]], $hb)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
    }
}
