<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: assemblies (087). A gift box is made of 2 bags of coffee (50.00 each) and a mug (30.00). Building 3 takes 6
 * bags and 3 mugs out of the main store at their cost (390.00) and puts 3 boxes in at that cost; the inventory account
 * is untouched and still equals the stock. Not more than is on hand; only stock items as parts; another company finds
 * none of it.
 */
final class AssemblyTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_assembly_is_built_from_its_parts_at_their_cost(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $inventory = fn () => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account('inventory')])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stock = fn (string $item) => $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $item, 'warehouse_id' => $c['store']])
            ->selectRaw('quantity::numeric(18,2)::text AS q, value::numeric(20,2)::text AS v')->first();
        $stockValue = fn () => (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v');

        $mug = $this->postJson('/api/v1/items', ['name' => 'Mug', 'type' => 'inventory', 'unit' => 'each', 'purchase_price' => '30'], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $mug, 'warehouse_id' => $c['store'], 'date' => '2026-11-10', 'quantity' => '10',
            'unit_cost' => '30', 'reason' => 'Mugs found in the store'], $h)->assertCreated();
        $box = $this->postJson('/api/v1/items', ['name' => 'Gift box', 'type' => 'inventory', 'unit' => 'box'], $h)->assertCreated()->json('id');
        $service = $this->postJson('/api/v1/items', ['name' => 'Wrapping', 'type' => 'service', 'unit' => 'each'], $h)->assertCreated()->json('id');
        $before = [$inventory(), $stockValue()];

        $this->postJson("/api/v1/items/{$box}/builds", ['warehouse_id' => $c['store'], 'quantity' => '1'], $h)->assertUnprocessable();   // no parts yet
        $this->putJson("/api/v1/items/{$box}/assembly", ['parts' => [['item_id' => $service, 'quantity' => '1']]], $h)->assertUnprocessable()->assertJsonValidationErrors('parts.0.item_id');
        $this->putJson("/api/v1/items/{$box}/assembly", ['parts' => [['item_id' => $b['stock_item'], 'quantity' => '1']]], $h)->assertUnprocessable();   // another company's
        $this->putJson("/api/v1/items/{$box}/assembly", ['parts' => [['item_id' => $c['stock_item'], 'quantity' => '2'], ['item_id' => $mug, 'quantity' => '1']]], $h)
            ->assertOk()->assertJsonCount(2, 'parts');

        $this->postJson("/api/v1/items/{$box}/builds", ['warehouse_id' => $c['store'], 'quantity' => '11'], $h)->assertUnprocessable();   // 22 bags, 20 on hand
        $built = $this->postJson("/api/v1/items/{$box}/builds", ['warehouse_id' => $c['store'], 'quantity' => '3', 'reference' => 'Holiday boxes'], $h)
            ->assertCreated()->assertJsonPath('value', '390.00')->json();
        $this->assertSame(['14.00', '700.00'], [$stock($c['stock_item'])->q, $stock($c['stock_item'])->v]);
        $this->assertSame(['7.00', '210.00'], [$stock($mug)->q, $stock($mug)->v]);
        $this->assertSame(['3.00', '390.00'], [$stock($box)->q, $stock($box)->v]);
        $this->assertSame($before, [$inventory(), $stockValue()], 'value moved from the parts to the boxes; none made or lost, nothing posted');
        $this->assertSame($stockValue(), $inventory());
        $this->getJson("/api/v1/items/{$box}/assembly", $h)->assertOk()->assertJsonCount(1, 'builds')->assertJsonPath('builds.0.value', '390.00')
            ->assertJsonPath('can.build', true);
        $this->assertContains('assembly_built', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $box])->pluck('action')->all());
        $this->assertSame(3, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'assembly_build_id' => $built['id']])->count());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/items/{$box}/assembly", ['X-Company-Id' => $b['company']])->assertNotFound();
        $this->postJson("/api/v1/items/{$box}/builds", ['warehouse_id' => $b['store'], 'quantity' => '1'], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
