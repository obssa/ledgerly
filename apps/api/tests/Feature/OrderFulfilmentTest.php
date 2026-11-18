<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: a sales order delivered in parts (067). The open order reserves its stock items; each invoice made from it
 * delivers what is given (never more than is left), and the rest is its back-order until it is all delivered or the
 * order is closed. An order partly delivered is no longer edited or cancelled, and another company never sees any of it.
 */
final class OrderFulfilmentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_order_is_delivered_in_parts_reserves_its_stock_and_is_closed_with_the_rest(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags on hand in the main store
        $h = ['X-Company-Id' => $c['company']];

        $order = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-02', 'due_date' => '2026-11-20',
            'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lines' => [
                ['description' => 'Coffee bags', 'quantity' => '30', 'unit_price' => '120', 'account_id' => $c['sales'], 'item_id' => $c['stock_item']],
                ['description' => 'Delivery to the shop', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $c['sales']],
            ]], $h)->assertCreated()->json();
        $id = $order['id'];
        [$bags, $delivery] = array_column($order['lines'], 'id');
        $this->postJson("/api/v1/sales-orders/{$id}/accept", [], $h)->assertOk()->assertJsonPath('can.close', false)
            ->assertJsonPath("delivery.{$bags}.remaining", '30.00');

        // The open order reserves its 30 bags: 20 on hand, 10 short.
        $item = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $this->assertSame(['20.00', '30.00', '-10.00'], [$item()['on_hand'], $item()['reserved'], $item()['available']]);
        $back = $this->getJson('/api/v1/sales-orders/back-orders', $h)->assertOk();
        $this->assertSame(['30.00', '1.00'], array_column(array_filter($back->json('data'), fn ($l) => $l['order_id'] === $id), 'remaining'));
        $this->assertSame('30.00', $back->json("stock.{$c['stock_item']}.reserved"));

        // First delivery: 12 bags, not the delivery charge yet. More than is left, or another order's line, is refused.
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $bags, 'quantity' => '31']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $c['draft'], 'quantity' => '1']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson("/api/v1/quotes/{$c['quote']}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $bags, 'quantity' => '1']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $first = $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [
            ['line_id' => $bags, 'quantity' => '12'], ['line_id' => $delivery, 'quantity' => '0']]], $h)->assertCreated()->json('id');
        $invoice = $this->getJson("/api/v1/invoices/{$first}", $h)->assertOk()->assertJsonPath('total', '1440.00')->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.source_line_id', $bags)->json();
        $shown = $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath("delivery.{$bags}.delivered", '12.00')->assertJsonPath("delivery.{$bags}.remaining", '18.00')
            ->assertJsonPath("delivery.{$delivery}.remaining", '1.00')->assertJsonPath('invoices.0.id', $first)
            ->assertJsonPath('can.convert', true)->assertJsonPath('can.close', true)->assertJsonPath('can.cancel', false)->assertJsonPath('can.edit', false)->json();
        $this->putJson("/api/v1/sales-orders/{$id}", ['contact_id' => $c['contact'], 'issue_date' => '2026-11-02', 'currency' => 'ETB', 'lock_version' => $shown['lock_version'],
            'lines' => [['description' => 'Coffee bags', 'quantity' => '40', 'unit_price' => '120', 'account_id' => $c['sales']]]], $h)->assertUnprocessable();
        $this->postJson("/api/v1/sales-orders/{$id}/cancel", [], $h)->assertUnprocessable();

        // The draft keeps its link when edited, and cannot be raised past what was ordered.
        $line = fn (string $qty) => ['description' => 'Coffee bags', 'quantity' => $qty, 'unit_price' => '120', 'account_id' => $c['sales'],
            'item_id' => $c['stock_item'], 'source_line_id' => $bags];
        $edit = fn (string $qty, int $version) => $this->putJson("/api/v1/invoices/{$first}", ['contact_id' => $c['contact'], 'issue_date' => substr($invoice['issue_date'], 0, 10),
            'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lock_version' => $version, 'lines' => [$line($qty)]], $h);
        $edit('31', $invoice['lock_version'])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $edit('15', $invoice['lock_version'])->assertOk()->assertJsonPath('lines.0.source_line_id', $bags);
        $this->postJson("/api/v1/invoices/{$first}/approve", [], $h)->assertOk();
        $this->assertSame(['5.00', '15.00', '-10.00'], [$item()['on_hand'], $item()['reserved'], $item()['available']]);

        // Another company sees none of it, and cannot deliver it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/sales-orders/back-orders', $hb)->assertOk()->json('data'), 'order_id'));
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice'], $hb)->assertNotFound();
        $this->postJson("/api/v1/sales-orders/{$id}/close", [], $hb)->assertNotFound();
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // Second delivery, 5 more bags; then the order is closed and the last 10 bags and the delivery charge drop off.
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $bags, 'quantity' => '5']]], $h)->assertCreated();
        $this->postJson("/api/v1/sales-orders/{$id}/close", ['note' => 'Customer took the rest elsewhere'], $h)->assertOk()
            ->assertJsonPath('status', 'converted')->assertJsonPath("delivery.{$bags}.remaining", '10.00')
            ->assertJsonPath('can.convert', false)->assertJsonPath('can.close', false);
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice'], $h)->assertUnprocessable();
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/sales-orders/back-orders', $h)->json('data'), 'order_id'));
        $this->assertSame('0.00', $item()['reserved']);

        // A fresh order delivered in full in one go is converted at once, as before.
        $whole = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Delivery', 'quantity' => '2', 'unit_price' => '200', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/sales-orders/{$whole}/accept", [], $h)->assertOk();
        $this->postJson("/api/v1/sales-orders/{$whole}/close", [], $h)->assertUnprocessable();
        $this->postJson("/api/v1/sales-orders/{$whole}/convert", ['to' => 'invoice'], $h)->assertCreated();
        $this->getJson("/api/v1/sales-orders/{$whole}", $h)->assertJsonPath('status', 'converted');

        $audit = $this->owner()->table('audit_log')->where('company_id', $c['company'])->where('subject_id', $id)->pluck('action')->all();
        $this->assertSame(2, count(array_keys($audit, 'sales_order_delivered', true)));
        $this->assertContains('sales_order_closed', $audit);
    }
}
