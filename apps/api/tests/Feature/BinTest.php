<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (098): bins. Two bins in the main store; the bags are kept in one, then moved to the other; the empty one
 * is archived, the full one is not. A count of a bin covers only what is kept in it, adjusted at cost as any count.
 * A viewer sees the bins and changes nothing; another company sees none of them.
 */
final class BinTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_stock_is_counted_one_bin_at_a_time(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store; its bin A-01 (empty)
        $h = ['X-Company-Id' => $c['company']];
        $bins = fn () => collect($this->getJson('/api/v1/bins', $h)->assertOk()->assertJsonPath('can.manage', true)->json('data'));

        $this->postJson('/api/v1/bins', ['warehouse_id' => $c['store'], 'code' => ' a-01 '], $h)->assertUnprocessable()->assertJsonValidationErrors('code');
        $cold = $this->postJson('/api/v1/bins', ['warehouse_id' => $c['store'], 'code' => 'Cold room', 'name' => 'Back of the store'], $h)->assertCreated()
            ->assertJsonPath('code', 'Cold room')->assertJsonPath('warehouse_id', $c['store'])->json('id');
        $shelf = $c['bin'];

        // The bags are kept on the shelf, then in the cold room: one bin per warehouse.
        $this->putJson("/api/v1/bins/{$shelf}/items", ['item_ids' => [$c['stock_item']]], $h)->assertOk()->assertJsonPath('items.0.id', $c['stock_item']);
        $this->putJson("/api/v1/bins/{$shelf}/items", ['item_ids' => [$c['sales']]], $h)->assertUnprocessable()->assertJsonValidationErrors('item_ids.0');
        $this->putJson("/api/v1/bins/{$cold}/items", ['item_ids' => [$c['stock_item']]], $h)->assertOk();
        $this->assertSame([[], [$c['stock_item']]], [
            array_column($bins()->firstWhere('id', $shelf)['items'], 'id'), array_column($bins()->firstWhere('id', $cold)['items'], 'id')]);
        $this->assertSame(1, $this->owner()->table('item_bins')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item']])->count());

        // An empty bin is archived; one with items is not.
        $this->putJson("/api/v1/bins/{$cold}", ['is_active' => false], $h)->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson("/api/v1/bins/{$shelf}", ['is_active' => false, 'code' => 'A-01 old'], $h)->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('code', 'A-01 old');
        $this->putJson("/api/v1/bins/{$shelf}/items", ['item_ids' => [$c['stock_item']]], $h)->assertUnprocessable();

        // A count of the cold room: the bags only. 18 found, 2 short at 50.00.
        $other = $this->postJson('/api/v1/items', ['name' => 'Mugs', 'type' => 'inventory', 'unit' => 'piece'], $h)->assertCreated()->json('id');
        $count = fn (array $counted, ?string $bin = null, ?string $warehouse = null) => $this->postJson('/api/v1/stock/counts', ['warehouse_id' => $warehouse ?? $c['store'],
            'date' => '2026-11-15', 'counted' => $counted, ...($bin ? ['bin_id' => $bin] : [])], $h);
        $count([['item_id' => $other, 'quantity' => '3']], $cold)->assertUnprocessable()->assertJsonValidationErrors('counted.0.item_id');   // not kept there
        $van = $this->postJson('/api/v1/warehouses', ['name' => 'Van'], $h)->assertCreated()->json('id');
        $count([['item_id' => $c['stock_item'], 'quantity' => '18']], $cold, $van)->assertUnprocessable()->assertJsonValidationErrors('bin_id');
        $count([['item_id' => $c['stock_item'], 'quantity' => '18']], $cold)->assertCreated()->assertJsonPath('bin', 'Cold room')->assertJsonPath('net', '-100.00');
        $this->assertSame('18.00', (string) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])->value('quantity'));
        $this->assertSame('Cold room', json_decode((string) $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'stock_counted'])->orderByDesc('id')->value('after'), true)['bin']);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'created', 'subject_type' => 'bin', 'subject_id' => $cold])->count());

        // A viewer sees the bins and changes nothing.
        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/v1/bins', $h)->assertOk()->assertJsonPath('can.manage', false);
        $this->postJson('/api/v1/bins', ['warehouse_id' => $c['store'], 'code' => 'B-01'], $h)->assertForbidden();

        // Another company sees none of them.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirs = collect($this->getJson('/api/v1/bins', ['X-Company-Id' => $b['company']])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($cold, $theirs);
        $this->putJson("/api/v1/bins/{$cold}", ['code' => 'Mine'], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
