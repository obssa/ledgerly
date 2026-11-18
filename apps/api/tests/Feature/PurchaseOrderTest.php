<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: purchase orders (068). An order to a supplier is issued (numbered PO-), its stock items show as on order,
 * and it is billed in parts as the supplier's invoices come in, never more than was ordered; the bills post as any
 * bill (stock in on approval). It is converted once all of it is billed, or closed with the rest dropped; partly
 * billed it is no longer edited or cancelled. Nothing on the order itself posts, and another company never sees it.
 */
final class PurchaseOrderTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_purchase_order_is_issued_billed_in_parts_and_closed(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags on hand in the main store
        $h = ['X-Company-Id' => $c['company']];
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();
        $item = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $inventory = $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');
        $order = fn (array $over = []) => [...['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'due_date' => '2026-11-30', 'currency' => 'ETB',
            'warehouse_id' => $c['store'], 'lines' => [
                ['description' => 'Green beans, bag', 'quantity' => '10', 'unit_price' => '40', 'account_id' => $c['rent'], 'item_id' => $c['stock_item']],
                ['description' => 'Delivery', 'quantity' => '1', 'unit_price' => '150', 'account_id' => $c['rent']],
            ]], ...$over];

        $this->postJson('/api/v1/purchase-orders', $order(['contact_id' => $c['contact']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $po = $this->postJson('/api/v1/purchase-orders', $order(), $h)->assertCreated()->assertJsonPath('type', 'purchase_order')
            ->assertJsonPath('status', 'draft')->assertJsonPath('total', '550.00')->assertJsonPath('amount_due', '0.00')->assertJsonPath('can.issue', true)->json();
        $id = $po['id'];
        [$beans, $delivery] = array_column($po['lines'], 'id');
        $before = $entries();
        $issued = $this->postJson("/api/v1/purchase-orders/{$id}/issue", [], $h)->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('can.bill', true)->assertJsonPath('can.close', false)->json('number');
        $this->assertMatchesRegularExpression('/^PO-\d{4}-0001$/', $issued);
        $this->assertSame(['20.00', '10.00'], [$item()['on_hand'], $item()['on_order']]);

        // Each kind stays on its own routes; another company does not find it at all.
        $this->getJson("/api/v1/bills/{$id}", $h)->assertNotFound();
        $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertNotFound();
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/purchase-orders/{$id}", $hb)->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$id}/bill", [], $hb)->assertNotFound();
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/purchase-orders/open', $hb)->assertOk()->json('data'), 'order_id'));
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // The first bill: 6 bags arrived, the delivery charge not yet. More than is left is refused.
        $this->postJson("/api/v1/purchase-orders/{$id}/bill", ['lines' => [['line_id' => $beans, 'quantity' => '11']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $first = $this->postJson("/api/v1/purchase-orders/{$id}/bill", ['lines' => [['line_id' => $beans, 'quantity' => '6'], ['line_id' => $delivery, 'quantity' => '0']]], $h)
            ->assertCreated()->assertJsonPath('type', 'bill')->json('id');
        $bill = $this->getJson("/api/v1/bills/{$first}", $h)->assertOk()->assertJsonPath('source_document_id', $id)->assertJsonPath('total', '240.00')
            ->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.source_line_id', $beans)->assertJsonPath('lines.0.account_id', $inventory)->json();
        $this->getJson("/api/v1/purchase-orders/{$id}", $h)->assertJsonPath('status', 'accepted')
            ->assertJsonPath("delivery.{$beans}.delivered", '6.00')->assertJsonPath("delivery.{$beans}.remaining", '4.00')->assertJsonPath('bills.0.id', $first)
            ->assertJsonPath('can.close', true)->assertJsonPath('can.cancel', false)->assertJsonPath('can.edit', false);
        $this->postJson("/api/v1/purchase-orders/{$id}/cancel", [], $h)->assertUnprocessable();
        $this->assertSame($before, $entries(), 'an order and a draft bill post nothing');

        // The draft bill keeps its link when edited (the supplier's number added), and cannot be raised past the order.
        $edit = fn (string $qty) => $this->putJson("/api/v1/bills/{$first}", ['contact_id' => $c['supplier'], 'reference' => 'SUP-881',
            'issue_date' => substr($bill['issue_date'], 0, 10), 'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lock_version' => $bill['lock_version'],
            'lines' => [['description' => 'Green beans, bag', 'quantity' => $qty, 'unit_price' => '40', 'account_id' => $c['rent'], 'item_id' => $c['stock_item'], 'source_line_id' => $beans]]], $h);
        $edit('11')->assertUnprocessable()->assertJsonValidationErrors('lines');
        $edit('6')->assertOk()->assertJsonPath('reference', 'SUP-881')->assertJsonPath('lines.0.source_line_id', $beans)->assertJsonPath('lines.0.account_id', $inventory);
        $this->postJson("/api/v1/bills/{$first}/approve", [], $h)->assertOk();
        $this->assertSame(['26.00', '4.00'], [$item()['on_hand'], $item()['on_order']]);
        $open = $this->getJson('/api/v1/purchase-orders/open', $h)->assertOk();
        $this->assertSame(['4.00', '1.00'], array_column(array_filter($open->json('data'), fn ($l) => $l['order_id'] === $id), 'remaining'));

        // The second bill takes all that is left: the order is converted.
        $this->postJson("/api/v1/purchase-orders/{$id}/bill", [], $h)->assertCreated();
        $this->getJson("/api/v1/purchase-orders/{$id}", $h)->assertJsonPath('status', 'converted')->assertJsonPath('can.bill', false);
        $this->assertSame('0.00', $item()['on_order']);

        // Another order, partly billed, then closed: the rest is no longer on order.
        $other = $this->postJson('/api/v1/purchase-orders', $order(), $h)->assertCreated()->json();
        $this->postJson("/api/v1/purchase-orders/{$other['id']}/close", [], $h)->assertUnprocessable();
        $this->postJson("/api/v1/purchase-orders/{$other['id']}/issue", [], $h)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$other['id']}/bill", ['lines' => [['line_id' => $other['lines'][0]['id'], 'quantity' => '2']]], $h)->assertCreated();
        $this->assertSame('8.00', $item()['on_order']);
        $this->postJson("/api/v1/purchase-orders/{$other['id']}/close", ['note' => 'Supplier out of stock'], $h)->assertOk()->assertJsonPath('status', 'converted');
        $this->assertSame('0.00', $item()['on_order']);
        $this->assertNotContains($other['id'], array_column($this->getJson('/api/v1/purchase-orders/open', $h)->json('data'), 'order_id'));

        // A draft nobody issued is cancelled.
        $draft = $this->postJson('/api/v1/purchase-orders', $order(), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/purchase-orders/{$draft}/cancel", [], $h)->assertOk()->assertJsonPath('status', 'void');

        $audit = $this->owner()->table('audit_log')->where('company_id', $c['company'])->whereIn('subject_id', [$id, $other['id']])->pluck('action')->all();
        foreach (['purchase_order_saved', 'purchase_order_issued', 'purchase_order_billed', 'purchase_order_converted', 'purchase_order_closed'] as $action) {
            $this->assertContains($action, $audit);
        }
    }
}
