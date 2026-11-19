<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: barcodes (085). A bag of coffee carries 4006381333931 and its case of 24 its own 14006381333938; searching
 * either finds the item. Within the company a barcode names one item or one unit: a second item or unit is refused with
 * the name of the one that has it. Another company may use the same barcode.
 */
final class BarcodeTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_barcode_finds_its_item_and_names_one_thing(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $item = $c['stock_item'];

        $this->putJson("/api/v1/items/{$item}", ['barcode' => ' 123'], $h)->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->putJson("/api/v1/items/{$item}", ['barcode' => '4006381333931'], $h)->assertOk()->assertJsonPath('barcode', '4006381333931');
        $this->postJson("/api/v1/items/{$item}/units", ['name' => 'case', 'factor' => '24', 'barcode' => '4006381333931'], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('barcode');   // the bag's own
        $this->postJson("/api/v1/items/{$item}/units", ['name' => 'case', 'factor' => '24', 'barcode' => '14006381333938'], $h)->assertCreated()
            ->assertJsonFragment(['name' => 'case', 'barcode' => '14006381333938']);

        // A second item cannot take either.
        $other = $this->postJson('/api/v1/items', ['name' => 'Green beans', 'type' => 'inventory', 'unit' => 'kg', 'barcode' => '14006381333938'], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('barcode')->json('errors.barcode.0');
        $this->assertStringContainsString('case', $other);
        $this->postJson('/api/v1/items', ['name' => 'Green beans', 'type' => 'inventory', 'unit' => 'kg', 'barcode' => '4006381333931'], $h)->assertUnprocessable();

        // Either barcode finds the item; the list carries the units' barcodes for scanning on a line.
        foreach (['4006381333931', '14006381333938'] as $code) {
            $this->getJson('/api/v1/items?search=' . $code, $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item);
        }
        $this->getJson('/api/v1/items?search=4006381333931', $h)->assertJsonFragment(['name' => 'case', 'barcode' => '14006381333938']);
        $this->assertContains('updated', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $item])->pluck('action')->all());

        // Another company may use the same barcode.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->putJson("/api/v1/items/{$b['stock_item']}", ['barcode' => '4006381333931'], ['X-Company-Id' => $b['company']])->assertOk();
        $this->getJson('/api/v1/items?search=4006381333931', ['X-Company-Id' => $b['company']])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b['stock_item']);
    }
}
