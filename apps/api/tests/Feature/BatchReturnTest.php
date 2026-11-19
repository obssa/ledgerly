<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4 (074, finished): the batch goods go back to the supplier in is chosen by hand. One bill brought in 5 tins of
 * batch L-2601 (expiring first) and 5 of L-2606; 2 tins of L-2606 go back on a supplier credit naming it, and the next 1
 * goes back without a batch, so it takes the one expiring first. A batch the company does not hold is refused.
 */
final class BatchReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_batch_sent_back_is_chosen_by_hand(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $inventory = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');
        $byBatch = fn (string $item) => $this->owner()->table('batch_balances as bb')->join('stock_batches as s', 's.id', '=', 'bb.batch_id')
            ->where(['bb.company_id' => $c['company'], 'bb.item_id' => $item])->orderBy('s.number')->pluck('bb.quantity', 's.number')->map(fn ($q) => (string) $q)->all();
        $milk = $this->postJson('/api/v1/items', ['name' => 'Milk powder 400 g', 'type' => 'inventory', 'unit' => 'tin', 'tracking' => 'batch'], $h)->assertCreated()->json('id');
        $line = fn (string $batch, string $expires) => ['description' => 'Milk powder', 'quantity' => '5', 'unit_price' => '200', 'account_id' => $c['rent'],
            'item_id' => $milk, 'batch_number' => $batch, 'batch_expires_on' => $expires];
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'reference' => 'MP-2',
            'warehouse_id' => $c['store'], 'lines' => [$line('L-2601', '2027-01-31'), $line('L-2606', '2027-06-30')]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $this->assertSame(['L-2601' => '5.00', 'L-2606' => '5.00'], $byBatch($milk));

        $credit = fn (string $qty, ?string $batch) => $this->postJson("/api/v1/bills/{$bill}/credits", ['issue_date' => '2026-11-06', 'notes' => 'Dented tins returned',
            'restock' => true, 'lines' => [array_filter(['description' => 'Milk powder', 'quantity' => $qty, 'unit_price' => '200', 'account_id' => $inventory,
                'item_id' => $milk, 'batch_number' => $batch])]], $h)->assertCreated()->json('id');

        // Two tins of L-2606, named by hand.
        $this->postJson('/api/v1/bills/' . $credit('2', 'L-2606') . '/approve', [], $h)->assertOk();
        $this->assertSame(['L-2601' => '5.00', 'L-2606' => '3.00'], $byBatch($milk));
        // One more without a batch: the one expiring first.
        $this->postJson('/api/v1/bills/' . $credit('1', null) . '/approve', [], $h)->assertOk();
        $this->assertSame(['L-2601' => '4.00', 'L-2606' => '3.00'], $byBatch($milk));
        // A batch the company does not hold is refused, and nothing moves.
        $this->postJson('/api/v1/bills/' . $credit('1', 'L-9999') . '/approve', [], $h)->assertUnprocessable();
        $this->assertSame(['L-2601' => '4.00', 'L-2606' => '3.00'], $byBatch($milk));
    }
}
