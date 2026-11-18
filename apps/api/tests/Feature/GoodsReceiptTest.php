<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: goods received notes and the three-way match (069). Goods that arrive on an issued purchase order come into
 * stock at the order's cost against goods received not invoiced (GRNI), never more than was ordered. The bill for them
 * clears GRNI instead of bringing the stock in again, never for more than arrived; a price above the order's goes onto
 * the goods still on hand, and the bill shows the difference as a warning. A note whose goods are billed is not voided;
 * voiding one takes its goods out again. The inventory account always equals the stock, and another company sees none
 * of it.
 */
final class GoodsReceiptTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_goods_are_received_then_billed_against_goods_received_not_invoiced(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $account = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $balance = fn (string $key) => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $account($key)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $stockValue = fn () => (string) $this->owner()->table('stock_balances')->where('company_id', $c['company'])
            ->selectRaw('coalesce(sum(value), 0)::numeric(20,2)::text AS v')->value('v');
        $item = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $show = fn (string $id) => $this->getJson("/api/v1/purchase-orders/{$id}", $h)->assertOk();

        $po = $this->postJson('/api/v1/purchase-orders', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'warehouse_id' => $c['store'], 'lines' => [
                ['description' => 'Green beans, bag', 'quantity' => '10', 'unit_price' => '40', 'account_id' => $c['rent'], 'item_id' => $c['stock_item']],
                ['description' => 'Delivery', 'quantity' => '1', 'unit_price' => '150', 'account_id' => $c['rent']],
            ]], $h)->assertCreated()->json();
        $id = $po['id'];
        [$beans, $delivery] = array_column($po['lines'], 'id');
        $receive = fn (array $lines, array $over = []) => $this->postJson("/api/v1/purchase-orders/{$id}/receive",
            ['received_on' => '2026-11-12', 'reference' => 'DN-55', 'lines' => $lines, ...$over], $h);

        $receive([['line_id' => $beans, 'quantity' => '6']])->assertUnprocessable();   // not issued yet
        $this->postJson("/api/v1/purchase-orders/{$id}/issue", [], $h)->assertOk()->assertJsonPath('can.receive', true);

        // What can and cannot be received.
        $receive([['line_id' => $delivery, 'quantity' => '1']])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $receive([['line_id' => $beans, 'quantity' => '11']])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $receive([['line_id' => $beans, 'quantity' => '6']], ['received_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('received_on');

        // 6 bags arrive: into stock at the order's 40.00, against GRNI.
        $first = $receive([['line_id' => $beans, 'quantity' => '6']])->assertCreated()->assertJsonPath('status', 'posted')
            ->assertJsonPath('order_number', $show($id)->json('number'))->assertJsonPath('lines.0.quantity', '6.00')->assertJsonPath('lines.0.amount', '240.00')->json();
        $this->assertSame('GRN-00001', $first['number']);
        $this->assertSame(['26.00', '4.00'], [$item()['on_hand'], $item()['on_order']]);
        $this->assertSame(['-240.00', '1240.00'], [$balance('grni'), $balance('inventory')]);
        $show($id)->assertJsonPath("delivery.{$beans}.received", '6.00')->assertJsonPath("delivery.{$beans}.billable", '6.00')
            ->assertJsonPath('receipts.0.number', 'GRN-00001')->assertJsonPath("arriving.{$beans}", '4.00')
            ->assertJsonPath('can.cancel', false)->assertJsonPath('can.edit', false)->assertJsonPath('can.receive', true);
        $this->postJson("/api/v1/purchase-orders/{$id}/cancel", [], $h)->assertUnprocessable();

        // The bill: no more beans than arrived; the supplier charged 42.00 a bag.
        $this->postJson("/api/v1/purchase-orders/{$id}/bill", ['lines' => [['line_id' => $beans, 'quantity' => '7']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $made = $this->postJson("/api/v1/purchase-orders/{$id}/bill", [], $h)->assertCreated()->json('id');
        $bill = $this->getJson("/api/v1/bills/{$made}", $h)->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.quantity', '6.00')->json();
        $this->putJson("/api/v1/bills/{$made}", ['contact_id' => $c['supplier'], 'reference' => 'SUP-77', 'issue_date' => substr($bill['issue_date'], 0, 10),
            'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lock_version' => $bill['lock_version'], 'lines' => [
                ['description' => 'Green beans, bag', 'quantity' => '6', 'unit_price' => '42', 'account_id' => $c['rent'], 'item_id' => $c['stock_item'], 'source_line_id' => $beans],
                ['description' => 'Delivery', 'quantity' => '1', 'unit_price' => '150', 'account_id' => $c['rent'], 'source_line_id' => $delivery],
            ]], $h)->assertOk();
        $match = $this->getJson("/api/v1/bills/{$made}", $h)->assertOk()->assertJsonPath('match.order_id', $id)
            ->assertJsonPath('match.lines.0.received', '6.00')->assertJsonPath('match.lines.0.price_differs', true)->assertJsonPath('match.lines.0.over', false)
            ->assertJsonPath('match.lines.1.by_receipt', false)->json('match.warnings');
        $this->assertSame(['Line 1: billed at 42.00, ordered at 40.00 (5.0%).'], $match);

        // Approved: no stock comes in again; GRNI is cleared, the 12.00 difference goes onto the bags on hand.
        $this->postJson("/api/v1/bills/{$made}/approve", [], $h)->assertOk();
        $this->assertSame('26.00', $item()['on_hand']);
        $this->assertSame(['0.00', '1252.00'], [$balance('grni'), $balance('inventory')]);
        $this->assertSame($stockValue(), $balance('inventory'));
        $this->assertSame(1, $this->owner()->table('bill_receipt_matches')->where(['company_id' => $c['company'], 'bill_id' => $made, 'quantity' => '6', 'amount' => '240'])->count());
        $posted = $this->getJson("/api/v1/bills/{$made}", $h)->json();
        $this->putJson("/api/v1/bills/{$made}", ['contact_id' => $c['supplier'], 'reference' => 'SUP-77', 'issue_date' => substr($posted['issue_date'], 0, 10),
            'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lock_version' => $posted['lock_version'], 'reason' => 'Price check', 'lines' => [
                ['description' => 'Green beans, bag', 'quantity' => '6', 'unit_price' => '41', 'account_id' => $c['rent'], 'item_id' => $c['stock_item'], 'source_line_id' => $beans],
            ]], $h)->assertUnprocessable();   // matched to goods received: voided and entered again, never edited
        $this->postJson("/api/v1/goods-receipts/{$first['id']}/void", [], $h)->assertUnprocessable()->assertJsonValidationErrors('goods_receipt');

        // The other 4 arrive; not billed yet, they are goods received not invoiced, and the order is not closed.
        $second = $receive([['line_id' => $beans, 'quantity' => '4']], ['reference' => 'DN-56'])->assertCreated()->assertJsonPath('lines.0.amount', '160.00')->json();
        $this->assertSame(['-160.00', '0.00'], [$balance('grni'), $item()['on_order']]);
        $unbilled = $this->getJson('/api/v1/goods-receipts/unbilled', $h)->assertOk()->assertJsonPath('total', '160.00')
            ->assertJsonPath('data.0.line_id', $beans)->assertJsonPath('data.0.open', '4.00');
        $this->assertCount(1, $unbilled->json('data'));
        $show($id)->assertJsonPath('can.close', false)->assertJsonPath('can.receive', false)->assertJsonPath('can.bill', true);
        $this->postJson("/api/v1/purchase-orders/{$id}/close", [], $h)->assertUnprocessable();
        $this->assertCount(2, $this->getJson("/api/v1/goods-receipts?purchase_order_id={$id}", $h)->assertOk()->json('data'));

        // Another company finds none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/goods-receipts/{$second['id']}", $hb)->assertNotFound();
        $this->postJson("/api/v1/goods-receipts/{$second['id']}/void", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$id}/receive", ['received_on' => '2026-11-12', 'lines' => [['line_id' => $beans, 'quantity' => '1']]], $hb)->assertNotFound();
        $this->assertSame([], array_intersect([$first['id'], $second['id']], array_column($this->getJson('/api/v1/goods-receipts', $hb)->assertOk()->json('data'), 'id')));
        $this->getJson('/api/v1/goods-receipts/unbilled', $hb)->assertOk()->assertJsonPath('total', '0.00');
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // The second note is voided: its bags go out again, GRNI is cleared, they can arrive again.
        $this->postJson("/api/v1/goods-receipts/{$second['id']}/void", ['reason' => 'Wrong delivery note'], $h)->assertOk()->assertJsonPath('status', 'void');
        $this->postJson("/api/v1/goods-receipts/{$second['id']}/void", [], $h)->assertUnprocessable();
        $this->assertSame(['26.00', '4.00', '0.00'], [$item()['on_hand'], $item()['on_order'], $balance('grni')]);
        $this->assertSame($stockValue(), $balance('inventory'));
        $show($id)->assertJsonPath('can.receive', true)->assertJsonPath('can.close', true);

        // The bill voided: the bags are goods received not invoiced again, the price difference comes off them.
        $this->postJson("/api/v1/bills/{$made}/void", ['reason' => 'Entered against the wrong delivery'], $h)->assertOk();
        $this->assertSame('-240.00', $balance('grni'));
        $this->assertSame($stockValue(), $balance('inventory'));
        // The last note voided: the bags go out at today's average; the difference from 240.00 is an inventory adjustment.
        $this->postJson("/api/v1/goods-receipts/{$first['id']}/void", [], $h)->assertOk();
        $this->assertSame(['20.00', '0.00'], [$item()['on_hand'], $balance('grni')]);
        $this->assertSame($stockValue(), $balance('inventory'));

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $id])->pluck('action')->all();
        $this->assertContains('goods_received', $audit);
        $this->assertContains('goods_receipt_voided', $audit);
    }
}
