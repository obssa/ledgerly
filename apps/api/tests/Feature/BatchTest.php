<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: batches and expiry (074). Milk powder is tracked by batch: what comes in names its batch (number and expiry),
 * what goes out takes the batch that expires first, split across batches when one is not enough, and a void puts it
 * back into the batch it left. A batch never goes below zero. Transfers keep their batches. The expiring list shows what
 * runs out soonest. Tracking is chosen before the first stock move; another company never sees a batch.
 */
final class BatchTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_tracked_stock_moves_by_batch_first_expiring_first(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $byBatch = fn (string $item) => $this->owner()->table('batch_balances as bb')->join('stock_batches as s', 's.id', '=', 'bb.batch_id')
            ->where(['bb.company_id' => $c['company'], 'bb.item_id' => $item])->orderBy('s.number')->pluck('bb.quantity', 's.number')->map(fn ($q) => (string) $q)->all();

        // Tracking is for a stock item, before its first move.
        $this->postJson('/api/v1/items', ['name' => 'Wrapping', 'type' => 'service', 'tracking' => 'batch'], $h)->assertUnprocessable()->assertJsonValidationErrors('tracking');
        $this->putJson("/api/v1/items/{$c['stock_item']}", ['tracking' => 'batch'], $h)->assertUnprocessable()->assertJsonValidationErrors('tracking');
        $milk = $this->postJson('/api/v1/items', ['name' => 'Milk powder 400 g', 'type' => 'inventory', 'unit' => 'tin', 'tracking' => 'batch'], $h)
            ->assertCreated()->assertJsonPath('tracking', 'batch')->json('id');

        // In: opening stock needs its batch; a bill brings in another.
        $opening = fn (array $over) => $this->postJson('/api/v1/stock/adjustments', [...['item_id' => $milk, 'warehouse_id' => $c['store'], 'date' => '2026-11-02',
            'quantity' => '10', 'unit_cost' => '200', 'opening' => true, 'reason' => 'Opening count'], ...$over], $h);
        $opening([])->assertUnprocessable()->assertJsonValidationErrors('batch');
        $opening(['batch' => 'L-2603', 'expires_on' => '2027-03-31'])->assertCreated();
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'reference' => 'MP-1',
            'warehouse_id' => $c['store'], 'lines' => [['description' => 'Milk powder', 'quantity' => '6', 'unit_price' => '200', 'account_id' => $c['rent'],
                'item_id' => $milk, 'batch_number' => 'L-2601', 'batch_expires_on' => '2027-01-31']]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $this->assertSame(['L-2601' => '6.00', 'L-2603' => '10.00'], $byBatch($milk));
        // The same batch number with another expiry is refused.
        $opening(['batch' => 'l-2601', 'expires_on' => '2027-02-28', 'opening' => false])->assertUnprocessable()->assertJsonValidationErrors('batch');

        // Out: 8 tins take the 6 expiring first and 2 of the next.
        $sale = $this->postJson('/api/v1/sales-receipts', ['contact_id' => $c['contact'], 'paid_from_account_id' => $c['bank'], 'payment_method' => 'cash',
            'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Milk powder', 'quantity' => '8', 'unit_price' => '300', 'account_id' => $c['sales'], 'item_id' => $milk]]], $h)->assertCreated()->json('id');
        $this->assertSame(['L-2601' => '0.00', 'L-2603' => '8.00'], $byBatch($milk));
        $this->assertSame(2, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $sale])->count());

        // A transfer keeps its batch: 3 tins of L-2603 to a second warehouse.
        $shop = $this->postJson('/api/v1/warehouses', ['name' => 'Shop'], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/transfers', ['item_id' => $milk, 'from_warehouse_id' => $c['store'], 'to_warehouse_id' => $shop, 'date' => '2026-11-11', 'quantity' => '3'], $h)
            ->assertCreated()->assertJsonPath('value', '600.00');
        $this->assertSame('3.00', (string) $this->owner()->table('batch_balances')->where(['company_id' => $c['company'], 'item_id' => $milk, 'warehouse_id' => $shop])->value('quantity'));

        // Out of a batch named: more than it holds is refused.
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $milk, 'warehouse_id' => $c['store'], 'date' => '2026-11-12', 'quantity' => '-1',
            'reason' => 'Damaged tin', 'batch' => 'L-2601'], $h)->assertUnprocessable();

        // The expiring list: L-2603 in both warehouses (L-2601 is empty); the item shows its batches, the first to expire first.
        $soon = $this->getJson('/api/v1/stock/batches?within_days=3650', $h)->assertOk()->json('data');
        $this->assertSame(['L-2603', 'L-2603'], array_column(array_values(array_filter($soon, fn ($r) => $r['item_id'] === $milk)), 'number'));
        $this->getJson("/api/v1/items/{$milk}", $h)->assertOk()->assertJsonPath('batches.0.number', 'L-2601');

        // Voided, the sale's tins go back to the batches they left: 6 to L-2601, 2 to L-2603 (5 left here after the transfer).
        $this->postJson("/api/v1/sales-receipts/{$sale}/void", ['reason' => 'Rang up twice'], $h)->assertOk();
        $here = $this->owner()->table('batch_balances as bb')->join('stock_batches as s', 's.id', '=', 'bb.batch_id')
            ->where(['bb.company_id' => $c['company'], 'bb.item_id' => $milk, 'bb.warehouse_id' => $c['store']])->orderBy('s.number')
            ->pluck('bb.quantity', 's.number')->map(fn ($q) => (string) $q)->all();
        $this->assertSame(['L-2601' => '6.00', 'L-2603' => '7.00'], $here);

        // Another company sees none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame([], array_filter($this->getJson('/api/v1/stock/batches', $hb)->assertOk()->json('data'), fn ($r) => $r['item_id'] === $milk));
        $this->getJson("/api/v1/items/{$milk}", $hb)->assertNotFound();
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
    }
}
