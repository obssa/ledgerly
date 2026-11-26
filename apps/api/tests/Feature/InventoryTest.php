<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: items, warehouses, opening stock and adjustments. The database costs every move out at weighted average;
 * the ledger's inventory account always equals the value of the stock on hand.
 */
final class InventoryTest extends TestCase
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

    private function adjust(string $quantity, ?string $unitCost = null, string $reason = 'Count on 10 Nov'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/stock/adjustments', array_filter(['item_id' => $this->c['stock_item'], 'warehouse_id' => $this->c['store'],
            'date' => '2026-11-10', 'quantity' => $quantity, 'unit_cost' => $unitCost, 'reason' => $reason]), $this->h);
    }

    public function test_opening_stock_and_adjustments_keep_the_ledger_equal_to_the_stock(): void
    {
        // The fixture's opening stock: 20 at 50.00, against opening balance equity.
        $this->getJson('/api/v1/stock', $this->h)->assertOk()
            ->assertJsonPath('data.0.quantity', '20.00')->assertJsonPath('data.0.value', '1000.00')->assertJsonPath('data.0.average_cost', '50.00');
        $this->assertSame('1000.00', $this->balance('1300'));

        // 3 damaged: out at the average (no cost asked), to inventory adjustments.
        $out = $this->adjust('-3', null, 'Bags torn in the store')->assertCreated()->assertJsonPath('amount', '-150.00')->json('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $out)->get()->keyBy('account_id');
        $this->assertSame('150.00', $lines[$this->account('5100')]->debit);
        $this->assertSame('150.00', $lines[$this->account('1300')]->credit);

        // 5 found at 62.00: the average moves to (850 + 310) / 22.
        $this->adjust('5', '62')->assertCreated()->assertJsonPath('amount', '310.00');
        $this->adjust('-7')->assertCreated()->assertJsonPath('amount', '-369.09');   // 7 x 1,160.00 / 22, rounded
        $this->getJson('/api/v1/stock', $this->h)->assertJsonPath('data.0.quantity', '15.00')->assertJsonPath('data.0.value', '790.91');
        $this->assertSame('790.91', $this->balance('1300'), 'the inventory account is the value of the stock on hand');

        // The last 15 out take all that is left.
        $this->adjust('-15')->assertCreated()->assertJsonPath('amount', '-790.91');
        $this->assertSame('0.00', $this->balance('1300'));
        $this->adjust('-1')->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $card = $this->getJson("/api/v1/reports/bin-card?item_id={$this->c['stock_item']}&warehouse_id={$this->c['store']}&from=2026-11-01&to=2026-11-30", $this->h)->assertOk();
        $this->assertCount(5, $card->json('moves'));
        // Each move with its document, that document's customer or supplier, and who made it.
        foreach (['document_id', 'document_type', 'document_number', 'name', 'created_by', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $card->json('moves.0'));
        }
        $this->assertSame('0.00', end($card->json()['moves'])['balance_value'] ?? $card->json('moves.4.balance_value'));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['stock_item'], 'action' => 'opening_stock'])->count());
    }

    public function test_adjustment_rules(): void
    {
        $this->adjust('4')->assertUnprocessable()->assertJsonValidationErrors('unit_cost');   // stock in needs its cost
        $this->adjust('4', '10', '')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->adjust('-21')->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $this->c['item'], 'warehouse_id' => $this->c['store'], 'date' => '2026-11-10',
            'quantity' => '1', 'unit_cost' => '1', 'reason' => 'x x x'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('item_id');   // a service
        $this->postJson('/api/v1/stock/adjustments', ['item_id' => $this->c['stock_item'], 'warehouse_id' => $this->c['store'], 'date' => '2026-11-10',
            'quantity' => '-1', 'opening' => true, 'unit_cost' => '1', 'reason' => 'x x x'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_items_and_warehouses(): void
    {
        $this->postJson('/api/v1/items', ['name' => 'Coffee again', 'sku' => 'RC-1K', 'type' => 'inventory'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/v1/items', ['name' => 'Wrong account', 'type' => 'service', 'income_account_id' => $this->account('6100')], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('income_account_id');
        $service = $this->postJson('/api/v1/items', ['name' => 'Cupping session', 'type' => 'service', 'sale_price' => '500', 'income_account_id' => $this->account('4100')], $this->h)
            ->assertCreated()->json('id');
        $this->putJson("/api/v1/items/{$service}", ['type' => 'non_inventory'], $this->h)->assertOk();   // no stock moves yet
        $this->putJson("/api/v1/items/{$this->c['stock_item']}", ['type' => 'service'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson("/api/v1/items/{$this->c['stock_item']}", ['reorder_point' => '10', 'is_active' => false], $this->h)->assertOk()->assertJsonPath('balances.0.quantity', '20.00');
        $this->getJson('/api/v1/items', $this->h)->assertOk()->assertJsonMissing(['id' => $this->c['stock_item']]);
        $this->getJson('/api/v1/items?include_archived=1&search=RC-1K', $this->h)->assertOk()->assertJsonPath('data.0.on_hand', '20.00');

        $this->postJson('/api/v1/warehouses', ['name' => 'Main store'], $this->h)->assertUnprocessable();
        $van = $this->postJson('/api/v1/warehouses', ['name' => 'Delivery van'], $this->h)->assertCreated()->json('id');
        $this->putJson("/api/v1/warehouses/{$van}", ['is_active' => false], $this->h)->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_who_may_do_what(): void
    {
        $this->actingAs($this->member('purchasing'), 'sanctum');
        $this->postJson('/api/v1/items', ['name' => 'Jute bags', 'type' => 'inventory'], $this->h)->assertCreated();
        $this->adjust('1', '1')->assertForbidden();
        $this->actingAs($this->member('viewer'), 'sanctum');
        $this->getJson('/api/v1/stock', $this->h)->assertOk();
        $this->postJson('/api/v1/items', ['name' => 'x', 'type' => 'service'], $this->h)->assertForbidden();
    }

    public function test_a_transfer_moves_stock_at_its_cost_and_the_valuation_agrees_with_the_ledger(): void
    {
        $van = $this->postJson('/api/v1/warehouses', ['name' => 'Delivery van'], $this->h)->assertCreated()->json('id');
        $this->adjust('5', '62')->assertCreated();   // 25 on hand worth 1,310.00: an average of 52.40
        $entries = $this->owner()->table('journal_entries')->where('company_id', $this->c['company'])->count();

        $this->postJson('/api/v1/stock/transfers', ['item_id' => $this->c['stock_item'], 'from_warehouse_id' => $this->c['store'], 'to_warehouse_id' => $van,
            'date' => '2026-11-11', 'quantity' => '10'], $this->h)->assertCreated()->assertJsonPath('value', '524.00');
        $this->assertSame($entries, $this->owner()->table('journal_entries')->where('company_id', $this->c['company'])->count(), 'a transfer posts nothing');
        $this->postJson('/api/v1/stock/transfers', ['item_id' => $this->c['stock_item'], 'from_warehouse_id' => $van, 'to_warehouse_id' => $van,
            'date' => '2026-11-11', 'quantity' => '1'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->postJson('/api/v1/stock/transfers', ['item_id' => $this->c['stock_item'], 'from_warehouse_id' => $van, 'to_warehouse_id' => $this->c['store'],
            'date' => '2026-11-11', 'quantity' => '10.01'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $valuation = $this->getJson('/api/v1/reports/inventory-valuation', $this->h)->assertOk()
            ->assertJsonPath('stock_value', '1310.00')->assertJsonPath('inventory_account', '1310.00')->assertJsonPath('difference', '0.00');
        $this->assertSame(['524.00', '786.00'], collect($valuation->json('rows'))->sortBy('warehouse')->pluck('value')->values()->all());   // Delivery van, Main store
        $this->getJson('/api/v1/reports/inventory-valuation?as_of=2026-11-01', $this->h)->assertJsonPath('stock_value', '0.00');   // before the opening stock

        // Running low: 25 on hand in all against a reorder point of 30.
        $this->putJson("/api/v1/items/{$this->c['stock_item']}", ['reorder_point' => '30'], $this->h)->assertOk();
        $this->getJson('/api/v1/stock/reorder', $this->h)->assertOk()->assertJsonPath('data.0.on_hand', '25.00');
        $this->putJson("/api/v1/items/{$this->c['stock_item']}", ['reorder_point' => '24.99'], $this->h)->assertOk();
        $this->getJson('/api/v1/stock/reorder', $this->h)->assertJsonCount(0, 'data');
    }

    private function balance(string $code): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->account($code)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
