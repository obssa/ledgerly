<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (073, finished): a bundle on an open sales order holds its stock parts. An order for 3 gift boxes (2 bags
 * each) reserves 6 bags; invoiced from the order, the boxes take their bags out and no longer hold any.
 */
final class BundleReserveTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_bundle_on_a_sales_order_reserves_its_parts(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $bags = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $box = $this->postJson('/api/v1/items', ['name' => 'Gift box', 'type' => 'bundle', 'sale_price' => '1500', 'income_account_id' => $c['sales']], $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$box}/components", ['components' => [['item_id' => $c['stock_item'], 'quantity' => '2']]], $h)->assertOk();

        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Gift box', 'quantity' => '3', 'unit_price' => '1500', 'account_id' => $c['sales'], 'item_id' => $box]]], $h)->assertCreated()->json('id');
        $this->assertSame('0.00', $bags()['reserved']);   // a draft order holds nothing
        $this->postJson("/api/v1/sales-orders/{$so}/accept", [], $h)->assertOk();
        $this->assertSame(['20.00', '6.00', '14.00'], [$bags()['on_hand'], $bags()['reserved'], $bags()['available']]);

        $invoice = $this->postJson("/api/v1/sales-orders/{$so}/convert", ['to' => 'invoice'], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $this->assertSame(['14.00', '0.00'], [$bags()['on_hand'], $bags()['reserved']]);
    }
}
