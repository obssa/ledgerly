<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (096): serial numbers. Two handsets are counted in by serial number, two more come on a bill (one listed
 * serial already in stock is refused); a cash sale names the handset it sells, another takes the two oldest; a
 * transfer moves the last one by its serial. Each unit moves on its own; a serial is in stock once at most. Another
 * company sees none of it.
 */
final class SerialNumberTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_serialised_stock_moves_unit_by_unit(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $inStock = fn (string $item, ?string $warehouse = null) => $this->owner()->table('batch_balances as bb')->join('stock_batches as s', 's.id', '=', 'bb.batch_id')
            ->where(['bb.company_id' => $c['company'], 'bb.item_id' => $item])->when($warehouse, fn ($q, $w) => $q->where('bb.warehouse_id', $w))
            ->where('bb.quantity', '>', 0)->orderBy('s.number')->pluck('s.number')->all();

        $phone = $this->postJson('/api/v1/items', ['name' => 'Feature phone', 'type' => 'inventory', 'unit' => 'piece', 'tracking' => 'serial'], $h)
            ->assertCreated()->assertJsonPath('tracking', 'serial')->json('id');

        // In: each unit names its serial number.
        $count = fn (array $over) => $this->postJson('/api/v1/stock/adjustments', [...['item_id' => $phone, 'warehouse_id' => $c['store'], 'date' => '2026-11-02',
            'quantity' => '2', 'unit_cost' => '300', 'opening' => true, 'reason' => 'Opening count'], ...$over], $h);
        $count([])->assertUnprocessable()->assertJsonValidationErrors('batch');
        $count(['batch' => 'IMEI-1'])->assertUnprocessable()->assertJsonValidationErrors('batch');   // one serial for two units
        $count(['batch' => 'IMEI-1, imei-1'])->assertUnprocessable()->assertJsonValidationErrors('batch');
        $count(['batch' => 'IMEI-1, IMEI-2'])->assertCreated();
        $this->assertSame(['IMEI-1', 'IMEI-2'], $inStock($phone));

        $bill = fn (string $serials, string $ref) => $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB',
            'reference' => $ref, 'warehouse_id' => $c['store'], 'lines' => [['description' => 'Feature phones', 'quantity' => '2', 'unit_price' => '310', 'account_id' => $c['rent'],
                'item_id' => $phone, 'batch_number' => $serials]]], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/bills/' . $bill("IMEI-3\nIMEI-1", 'PH-1') . '/approve', [], $h)->assertUnprocessable()->assertJsonValidationErrors('batch');   // IMEI-1 is here
        $this->postJson('/api/v1/bills/' . $bill("IMEI-3\nIMEI-4", 'PH-2') . '/approve', [], $h)->assertOk();
        $this->assertSame(['IMEI-1', 'IMEI-2', 'IMEI-3', 'IMEI-4'], $inStock($phone));
        $this->assertSame(['310.00', '310.00'], $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'item_id' => $phone, 'kind' => 'purchase'])
            ->orderBy('seq')->pluck('amount')->map(fn ($a) => (string) $a)->all());

        // Out: one sale names its handset; another takes the two oldest.
        $sale = fn (string $quantity, ?string $serial) => $this->postJson('/api/v1/sales-receipts', ['contact_id' => $c['contact'], 'paid_from_account_id' => $c['bank'],
            'payment_method' => 'cash', 'issue_date' => '2026-11-10', 'currency' => 'ETB', 'warehouse_id' => $c['store'],
            'lines' => [['description' => 'Feature phone', 'quantity' => $quantity, 'unit_price' => '450', 'account_id' => $c['sales'], 'item_id' => $phone,
                ...($serial ? ['batch_number' => $serial] : [])]]], $h);
        $sale('1', 'IMEI-2')->assertCreated();
        $this->assertSame(['IMEI-1', 'IMEI-3', 'IMEI-4'], $inStock($phone));
        $sold = $sale('2', null)->assertCreated()->json('id');
        $this->assertSame(['IMEI-4'], $inStock($phone));
        $this->assertSame(2, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $sold])->count());

        // A transfer moves the last handset by its serial; one not in stock cannot go out.
        $shop = $this->postJson('/api/v1/warehouses', ['name' => 'Shop'], $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/stock/transfers', ['item_id' => $phone, 'from_warehouse_id' => $c['store'], 'to_warehouse_id' => $shop, 'date' => '2026-11-11', 'quantity' => '1'], $h)
            ->assertCreated();
        $this->assertSame(['IMEI-4'], $inStock($phone, $shop));
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $phone, 'warehouse_id' => $shop, 'date' => '2026-11-12', 'quantity' => '-1', 'reason' => 'Lost',
            'batch' => 'IMEI-2'], $h)->assertUnprocessable()->assertJsonValidationErrors('batch');

        // Another company sees none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirs = collect($this->getJson('/api/v1/stock/batches', ['X-Company-Id' => $b['company']])->assertOk()->json('data'))->pluck('item_id')->all();
        $this->assertNotContains($phone, $theirs);
    }
}
