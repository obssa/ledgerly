<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: bin locations (090). The bags are kept in "Aisle 3 / B2" of the main store: the item shows it beside what is
 * there; it moves to "Aisle 4", then is cleared. Only a stock item has a bin, in a warehouse of the company; another
 * company finds none of it.
 */
final class BinLocationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_stock_item_is_kept_in_a_bin_of_a_warehouse(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags in the main store
        $h = ['X-Company-Id' => $c['company']];
        $item = $c['stock_item'];
        $set = fn (array $body, array $hh = []) => $this->putJson("/api/v1/items/{$item}/locations", $body, $hh ?: $h);

        $set(['warehouse_id' => $c['store'], 'bin' => 'Aisle 3 / B2'])->assertOk()->assertJsonPath('locations.0.bin', 'Aisle 3 / B2')
            ->assertJsonPath('balances.0.bin', 'Aisle 3 / B2');
        $set(['warehouse_id' => $c['store'], 'bin' => 'Aisle 4'])->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.bin', 'Aisle 4');
        $set(['warehouse_id' => $b['store'], 'bin' => 'Shelf 1'])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');   // another company's
        $service = $this->postJson('/api/v1/items', ['name' => 'Delivery', 'type' => 'service'], $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$service}/locations", ['warehouse_id' => $c['store'], 'bin' => 'Shelf 1'], $h)->assertUnprocessable()->assertJsonValidationErrors('bin');
        $this->assertContains('bin_location_set', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $item])->pluck('action')->all());

        $set(['warehouse_id' => $c['store'], 'bin' => ''])->assertOk()->assertJsonCount(0, 'locations');

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $set(['warehouse_id' => $b['store'], 'bin' => 'Shelf 1'], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
