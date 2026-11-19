<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: customer groups and price lists (080). A "Wholesale" list prices coffee at 420.00; a customer put on it
 * gets that price suggested on their invoices. Names are unique, only the company's own items are priced, a list with
 * customers on it stays in use, and another company neither sees the list nor puts its customers on it.
 */
final class PriceListTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_price_list_prices_items_for_the_customers_on_it(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];

        $list = $this->postJson('/api/v1/price-lists', ['name' => 'Wholesale', 'notes' => 'Cafes and hotels'], $h)->assertCreated()
            ->assertJsonPath('currency', 'ETB')->assertJsonPath('is_active', true)->json('id');
        $this->postJson('/api/v1/price-lists', ['name' => ' wholesale '], $h)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/price-lists', ['name' => 'X'], $h)->assertUnprocessable();

        // Prices: the company's own items only, never below zero.
        $this->putJson("/api/v1/price-lists/{$list}/prices", ['prices' => [['item_id' => $b['item'], 'unit_price' => '400']]], $h)->assertUnprocessable()->assertJsonValidationErrors('prices');
        $this->putJson("/api/v1/price-lists/{$list}/prices", ['prices' => [['item_id' => $c['item'], 'unit_price' => '-1']]], $h)->assertUnprocessable();
        $this->putJson("/api/v1/price-lists/{$list}/prices", ['prices' => [['item_id' => $c['item'], 'unit_price' => '420']]], $h)->assertOk()
            ->assertJsonCount(1, 'prices')->assertJsonPath('prices.0.unit_price', '420.00');
        $this->putJson("/api/v1/price-lists/{$list}/prices", ['prices' => [['item_id' => $c['item'], 'unit_price' => '415.5']]], $h)->assertOk()
            ->assertJsonPath('prices.0.unit_price', '415.50');

        // A customer on the list; the invoice form sees the list's prices.
        $this->putJson("/api/v1/customers/{$c['contact']}", ['price_list_id' => $list], $h)->assertOk()->assertJsonPath('price_list_id', $list);
        $this->getJson('/api/v1/customers', $h)->assertOk()->assertJsonPath('data.0.price_list_id', $list);
        $this->getJson('/api/v1/price-lists/prices', $h)->assertOk()->assertJsonFragment(['id' => $list, 'currency' => 'ETB', 'prices' => [$c['item'] => '415.50']]);
        $this->getJson("/api/v1/price-lists/{$list}", $h)->assertOk()->assertJsonCount(1, 'customers');
        $this->getJson('/api/v1/price-lists', $h)->assertOk()->assertJsonFragment(['id' => $list, 'customers' => 1, 'items' => 1]);

        // In use while customers are on it; then archived, and no customer is put on it.
        $this->putJson("/api/v1/price-lists/{$list}", ['is_active' => false], $h)->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson("/api/v1/customers/{$c['contact']}", ['price_list_id' => null], $h)->assertOk()->assertJsonPath('price_list_id', null);
        $this->putJson("/api/v1/price-lists/{$list}", ['is_active' => false, 'name' => 'Old wholesale'], $h)->assertOk()->assertJsonPath('name', 'Old wholesale');
        $this->putJson("/api/v1/customers/{$c['contact']}", ['price_list_id' => $list], $h)->assertUnprocessable()->assertJsonValidationErrors('price_list_id');
        $this->getJson('/api/v1/price-lists/prices', $h)->assertOk()->assertJsonMissing(['id' => $list]);

        $actions = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $list])->pluck('action')->all();
        foreach (['price_list_created', 'price_list_prices_set', 'price_list_updated'] as $action) {
            $this->assertContains($action, $actions);
        }

        // Another company finds none of it, and cannot put its customers on it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/price-lists/{$list}", $hb)->assertNotFound();
        $this->putJson("/api/v1/price-lists/{$list}/prices", ['prices' => []], $hb)->assertNotFound();
        $this->getJson('/api/v1/price-lists', $hb)->assertOk()->assertJsonMissing(['id' => $list]);
        $this->putJson("/api/v1/customers/{$b['contact']}", ['price_list_id' => $list], $hb)->assertUnprocessable()->assertJsonValidationErrors('price_list_id');
    }
}
