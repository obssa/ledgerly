<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' Customize (111): Sales and Purchase form content in the company's settings; classes and locations tracked
 * off, per transaction or per line, carried to the ledger when posted; a line's service date; the company's custom
 * fields, only those kept. Database rules: T136. Isolation: TenantIsolationTest.
 */
final class FormContentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    private function content(array $content): \Illuminate\Testing\TestResponse
    {
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');

        return $this->putJson('/api/v1/company', ['version' => $version, 'form_content' => $content], $this->h);
    }

    public function test_the_company_customizes_its_sales_and_purchase_forms(): void
    {
        $this->getJson('/api/v1/company', $this->h)->assertOk()
            ->assertJsonPath('form_content.track_classes', 'off')->assertJsonPath('form_content.sales.service_date', false)
            ->assertJsonPath('form_content.sales.tags', true)->assertJsonPath('form_content.custom_fields', []);

        $this->content(['track_classes' => 'line', 'track_locations' => 'transaction', 'sales' => ['service_date' => true, 'sku' => true],
            'custom_fields' => [['name' => 'PO number', 'print' => true], ['name' => 'Crop year', 'sales' => true, 'purchases' => true, 'print' => false]]])
            ->assertOk()->assertJsonPath('form_content.track_classes', 'line')->assertJsonPath('form_content.sales.service_date', true)
            ->assertJsonPath('form_content.sales.shipping', false)->assertJsonPath('form_content.purchases.service_date', false)
            ->assertJsonPath('form_content.custom_fields.1.purchases', true)->assertJsonPath('form_content.custom_fields.0.purchases', false);
        // Sent again in part: the rest stays.
        $this->content(['purchases' => ['service_date' => true]])->assertOk()
            ->assertJsonPath('form_content.sales.sku', true)->assertJsonPath('form_content.purchases.service_date', true)->assertJsonPath('form_content.track_classes', 'line');

        $this->content(['track_classes' => 'always'])->assertUnprocessable()->assertJsonValidationErrors('form_content.track_classes');
        $this->content(['sales' => ['colour' => true]])->assertUnprocessable()->assertJsonValidationErrors('form_content.sales');
        $this->content(['custom_fields' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C'], ['name' => 'D']]])->assertUnprocessable()->assertJsonValidationErrors('form_content.custom_fields');
        $this->content(['custom_fields' => [['name' => 'Lot'], ['name' => 'lot']]])->assertUnprocessable();
        $this->assertSame(2, $this->owner()->table('audit_log')->where('company_id', $this->c['company'])->whereRaw("after::text LIKE '%form_content%'")->count());
    }

    public function test_classes_and_locations_go_where_the_company_tracks_them_and_on_to_the_ledger(): void
    {
        $retail = $this->postJson('/api/v1/dimensions', ['kind' => 'class', 'code' => 'RETAIL', 'name' => 'Retail'], $this->h)->assertCreated()->json('id');
        $export = $this->postJson('/api/v1/dimensions', ['kind' => 'class', 'code' => 'EXPORT', 'name' => 'Export'], $this->h)->assertCreated()->json('id');
        $this->content(['track_classes' => 'line', 'track_locations' => 'transaction', 'custom_fields' => [['name' => 'PO number']]])->assertOk();
        $line = fn (string $class, string $price) => ['description' => 'Coffee', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $this->c['sales'],
            'class_id' => $class, 'location_id' => $this->c['dimension'], 'service_date' => '2026-10-31'];

        $saved = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'class_id' => $retail, 'location_id' => $this->c['dimension'], 'custom_fields' => ['PO number' => ' HGK-77 ', 'Planted' => 'x'],
            'lines' => [$line($retail, '600'), $line($export, '400')]], $this->h)->assertCreated()
            // Classes per line: the document's own is not kept; the location per transaction: the lines' are not.
            ->assertJsonPath('class_id', null)->assertJsonPath('location_id', $this->c['dimension'])
            ->assertJsonPath('lines.0.class_id', $retail)->assertJsonPath('lines.1.class_id', $export)->assertJsonPath('lines.0.location_id', null)
            ->assertJsonPath('lines.0.service_date', '2026-10-31')->assertJsonPath('custom_fields', ['PO number' => 'HGK-77']);

        $entry = $this->postJson("/api/v1/invoices/{$saved->json('id')}/approve", [], $this->h)->assertOk()->json('journal_entry_id');
        $income = $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $this->c['sales']])->get()->keyBy('class_id');
        $this->assertEqualsCanonicalizing([$retail, $export], $income->keys()->all());
        $this->assertSame('600.00', (string) $income[$retail]->credit);
        $this->assertSame($this->c['dimension'], $income[$export]->location_id, 'the document\'s location on each line');

        // Not tracked: not kept.
        $this->content(['track_classes' => 'off', 'track_locations' => 'off'])->assertOk();
        $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'location_id' => $this->c['dimension'],
            'lines' => [$line($retail, '100')]], $this->h)->assertCreated()->assertJsonPath('location_id', null)->assertJsonPath('lines.0.class_id', null);
        // A location is not a class.
        $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [$line($this->c['dimension'], '100')]], $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines.0.class_id');
    }
}
