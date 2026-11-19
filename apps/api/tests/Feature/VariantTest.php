<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: variants (089). A T-shirt in sizes S, M, L and colours Red, Blue becomes six variants, each an item of its
 * own at the shirt's price (its own stock, SKU, barcode); making them again adds only the new ones; a variant has no
 * variants; another company finds none of it.
 */
final class VariantTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_items_variants_are_made_from_its_options(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $shirt = $this->postJson('/api/v1/items', ['name' => 'T-shirt', 'type' => 'inventory', 'unit' => 'each', 'sale_price' => '450', 'purchase_price' => '200'], $h)
            ->assertCreated()->json('id');
        $this->getJson("/api/v1/items/{$shirt}", $h)->assertOk()->assertJsonPath('can.variants', true)->assertJsonCount(0, 'variants');

        $made = $this->postJson("/api/v1/items/{$shirt}/variants", ['options' => [['name' => 'Size', 'values' => ['S', 'M', 'L']], ['name' => 'Colour', 'values' => ['Red', 'Blue']]]], $h)
            ->assertCreated()->assertJsonCount(6, 'made')->assertJsonCount(6, 'item.variants')->json();
        $this->assertContains('M / Red', array_column($made['made'], 'variant_label'));
        $medium = collect($made['made'])->firstWhere('variant_label', 'M / Red')['id'];
        $this->getJson("/api/v1/items/{$medium}", $h)->assertOk()->assertJsonPath('name', 'T-shirt — M / Red')->assertJsonPath('sale_price', '450.00')
            ->assertJsonPath('type', 'inventory')->assertJsonPath('parent.id', $shirt)->assertJsonPath('can.variants', false);
        // Each is an item of its own: its own barcode.
        $this->putJson("/api/v1/items/{$medium}", ['barcode' => '6290001000017'], $h)->assertOk();

        // Again with an extra size: only XL is new (two variants).
        $this->postJson("/api/v1/items/{$shirt}/variants", ['options' => [['name' => 'Size', 'values' => ['M', 'XL']], ['name' => 'Colour', 'values' => ['Red', 'Blue']]]], $h)
            ->assertCreated()->assertJsonCount(2, 'made');
        $this->postJson("/api/v1/items/{$medium}/variants", ['options' => [['name' => 'Fit', 'values' => ['Slim']]]], $h)->assertUnprocessable();   // a variant itself
        $this->postJson("/api/v1/items/{$shirt}/variants", ['options' => [['name' => 'Size', 'values' => array_map('strval', range(1, 11))], ['name' => 'Colour', 'values' => array_map('strval', range(1, 10))]]], $h)
            ->assertUnprocessable();   // 110 at a time is too many
        $this->assertContains('variants_made', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $shirt])->pluck('action')->all());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/items/{$shirt}/variants", ['options' => [['name' => 'Size', 'values' => ['S']]]], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
