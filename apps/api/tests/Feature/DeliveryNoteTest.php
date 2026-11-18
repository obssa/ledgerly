<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: delivery notes (071). Goods that leave on a confirmed sales order go out of stock that day at their cost,
 * and the cost of goods sold is recorded then, never more than was ordered. The invoice for them bills them without
 * moving the stock or the cost again, never for more than left. A note whose goods are invoiced is not voided; voiding
 * one brings its goods back at the cost they left with. The inventory account always equals the stock, and another
 * company sees none of it.
 */
final class DeliveryNoteTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_goods_leave_on_delivery_notes_and_are_invoiced_without_moving_again(): void
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
        $show = fn (string $id) => $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertOk();
        $cogs = $balance('cogs');

        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'warehouse_id' => $c['store'], 'lines' => [
                ['description' => 'Coffee bags', 'quantity' => '10', 'unit_price' => '120', 'account_id' => $c['sales'], 'item_id' => $c['stock_item']],
                ['description' => 'Delivery to the shop', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $c['sales']],
            ]], $h)->assertCreated()->json();
        $id = $so['id'];
        [$bags, $delivery] = array_column($so['lines'], 'id');
        $deliver = fn (array $lines, array $over = []) => $this->postJson("/api/v1/sales-orders/{$id}/deliver",
            ['delivered_on' => '2026-11-12', 'reference' => 'WB-12', 'lines' => $lines, ...$over], $h);

        $deliver([['line_id' => $bags, 'quantity' => '6']])->assertUnprocessable();   // not confirmed yet
        $this->postJson("/api/v1/sales-orders/{$id}/accept", [], $h)->assertOk()->assertJsonPath('can.deliver', true);
        $this->assertSame(['20.00', '10.00'], [$item()['on_hand'], $item()['reserved']]);

        $deliver([['line_id' => $delivery, 'quantity' => '1']])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $deliver([['line_id' => $bags, 'quantity' => '11']])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $deliver([['line_id' => $bags, 'quantity' => '6']], ['delivered_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('delivered_on');

        // 6 bags leave: out of stock at their 50.00, the cost of goods sold recorded now.
        $first = $deliver([['line_id' => $bags, 'quantity' => '6']])->assertCreated()->assertJsonPath('status', 'posted')
            ->assertJsonPath('lines.0.quantity', '6.00')->assertJsonPath('cost', '300.00')->json();
        $this->assertSame('DN-00001', $first['number']);
        $this->assertSame(['14.00', '4.00'], [$item()['on_hand'], $item()['reserved']]);
        $this->assertSame(bcadd($cogs, '300.00', 2), $balance('cogs'));
        $this->assertSame($stockValue(), $balance('inventory'));
        $show($id)->assertJsonPath("delivery.{$bags}.shipped", '6.00')->assertJsonPath("delivery.{$bags}.billable", '6.00')
            ->assertJsonPath('delivery_notes.0.number', 'DN-00001')->assertJsonPath("leaving.{$bags}", '4.00')
            ->assertJsonPath('can.cancel', false)->assertJsonPath('can.edit', false);
        $this->postJson("/api/v1/sales-orders/{$id}/cancel", [], $h)->assertUnprocessable();

        // The invoice: no more bags than left; it bills them without moving them or their cost again.
        $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $bags, 'quantity' => '7']]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $made = $this->postJson("/api/v1/sales-orders/{$id}/convert", ['to' => 'invoice'], $h)->assertCreated()->json('id');
        $this->getJson("/api/v1/invoices/{$made}", $h)->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.quantity', '6.00');
        $this->postJson("/api/v1/invoices/{$made}/approve", [], $h)->assertOk();
        $this->assertSame('14.00', $item()['on_hand']);
        $this->assertSame(bcadd($cogs, '300.00', 2), $balance('cogs'), 'the cost was recorded on delivery, not again on the invoice');
        $this->assertSame($stockValue(), $balance('inventory'));
        $this->assertSame(1, $this->owner()->table('invoice_delivery_matches')->where(['company_id' => $c['company'], 'invoice_id' => $made, 'quantity' => '6'])->count());
        $this->postJson("/api/v1/delivery-notes/{$first['id']}/void", [], $h)->assertUnprocessable()->assertJsonValidationErrors('delivery_note');

        // The other 4 leave; not invoiced yet, they are listed, and the order is not closed.
        $second = $deliver([['line_id' => $bags, 'quantity' => '4']], ['reference' => 'WB-13'])->assertCreated()->assertJsonPath('cost', '200.00')->json();
        $this->assertSame(['10.00', '0.00'], [$item()['on_hand'], $item()['reserved']]);
        $open = $this->getJson('/api/v1/delivery-notes/uninvoiced', $h)->assertOk()->assertJsonPath('data.0.line_id', $bags)->assertJsonPath('data.0.open', '4.00');
        $this->assertCount(1, $open->json('data'));
        $show($id)->assertJsonPath('can.close', false)->assertJsonPath('can.deliver', false)->assertJsonPath('can.convert', true);
        $this->postJson("/api/v1/sales-orders/{$id}/close", [], $h)->assertUnprocessable();
        $this->assertCount(2, $this->getJson("/api/v1/delivery-notes?sales_order_id={$id}", $h)->assertOk()->json('data'));

        // Another company finds none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/delivery-notes/{$second['id']}", $hb)->assertNotFound();
        $this->postJson("/api/v1/delivery-notes/{$second['id']}/void", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/sales-orders/{$id}/deliver", ['delivered_on' => '2026-11-12', 'lines' => [['line_id' => $bags, 'quantity' => '1']]], $hb)->assertNotFound();
        $this->assertSame([], array_intersect([$first['id'], $second['id']], array_column($this->getJson('/api/v1/delivery-notes', $hb)->assertOk()->json('data'), 'id')));
        $this->assertSame([], $this->getJson('/api/v1/delivery-notes/uninvoiced', $hb)->assertOk()->json('data'));
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // The second note voided: its bags come back at the cost they left with.
        $this->postJson("/api/v1/delivery-notes/{$second['id']}/void", ['reason' => 'Lorry turned back'], $h)->assertOk()->assertJsonPath('status', 'void');
        $this->postJson("/api/v1/delivery-notes/{$second['id']}/void", [], $h)->assertUnprocessable();
        $this->assertSame(['14.00', '4.00'], [$item()['on_hand'], $item()['reserved']]);
        $this->assertSame(bcadd($cogs, '300.00', 2), $balance('cogs'));
        $this->assertSame($stockValue(), $balance('inventory'));
        $show($id)->assertJsonPath('can.deliver', true)->assertJsonPath('can.close', true);

        // The invoice is registered with the tax authority (sandbox): corrected by credit note, never voided, so the first
        // note stays. A voided invoice giving its quantity back is checked in the database (T97).
        $this->postJson("/api/v1/delivery-notes/{$first['id']}/void", [], $h)->assertUnprocessable();

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $id])->pluck('action')->all();
        $this->assertContains('goods_delivered', $audit);
        $this->assertContains('delivery_note_voided', $audit);
    }
}
