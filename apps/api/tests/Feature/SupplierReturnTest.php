<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: goods sent back to the supplier on a supplier credit (059). They leave the bill's warehouse at the weighted
 * average, the credit's entry takes the line amounts off inventory and any difference to inventory adjustment, never
 * more goes back than the bill brought in, and a price correction on goods kept goes to inventory adjustment, so the
 * inventory account always equals the stock.
 */
final class SupplierReturnTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_goods_go_back_at_their_average_cost_and_the_inventory_account_keeps_equal_to_the_stock(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $cups = $this->postJson('/api/v1/items', ['name' => 'Paper cups', 'type' => 'inventory', 'unit' => 'box', 'purchase_price' => '30'], $h)->assertCreated()->json('id');
        $inventory = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');
        $adjustment = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory_adjustment'])->value('id');
        $expense = (string) $this->owner()->table('accounts')->where('company_id', $c['company'])->where('type', 'expense')->value('id');
        $line = fn (string $qty, string $price) => ['description' => 'Paper cups', 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $expense, 'item_id' => $cups];
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05', 'reference' => 'PC-9', 'currency' => 'ETB',
            'warehouse_id' => $c['store'], 'lines' => [$line('10', '30')]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $stock = fn () => $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $cups, 'warehouse_id' => $c['store']])->first();
        $books = fn () => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $inventory])
            ->selectRaw('coalesce(sum(debit) - sum(credit), 0) AS b')->value('b');
        $before = bcsub($books(), (string) $stock()->value, 2);   // the fixture's own stock, already in step

        $credit = fn (array $lines, bool $restock) => $this->postJson("/api/v1/bills/{$bill}/credits", [
            'issue_date' => '2026-11-06', 'notes' => 'Cups returned, misprinted', 'lines' => $lines, 'restock' => $restock], $h);
        $creditLine = fn (string $qty, string $price) => [...$line($qty, $price), 'account_id' => $inventory];   // as the form copies it from the bill

        // Four boxes back, credited at 25.00 (they cost 30.00): the stock falls 120.00, the entry credits inventory 100.00 + 20.00.
        $back = $credit([$creditLine('4', '25')], true)->assertCreated()->assertJsonPath('restock', true)->json('id');
        $entry = $this->postJson("/api/v1/bills/{$back}/approve", [], $h)->assertOk()->json('journal_entry_id');
        $this->assertSame(['6.00', '180.00'], [number_format((float) $stock()->quantity, 2, '.', ''), number_format((float) $stock()->value, 2, '.', '')]);
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get();
        $this->assertSame('120.00', bcadd('0', (string) $lines->where('account_id', $inventory)->sum('credit'), 2));
        $this->assertSame('20.00', number_format((float) $lines->where('account_id', $adjustment)->sum('debit'), 2, '.', ''));
        $this->assertSame($before, bcsub($books(), (string) $stock()->value, 2), 'the inventory account still equals the stock');

        // Seven more cannot go back (six are left); a price correction on goods kept moves no stock and keeps the books in step.
        $tooMany = $credit([$creditLine('7', '1')], true)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$tooMany}/approve", [], $h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $price = $credit([$creditLine('6', '5')], false)->assertCreated()->assertJsonPath('restock', false)->assertJsonPath('lines.0.account_id', $adjustment)->json('id');
        $this->postJson("/api/v1/bills/{$price}/approve", [], $h)->assertOk();
        $this->assertSame(0, $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'document_id' => $price])->count());
        $this->assertSame('6.00', number_format((float) $stock()->quantity, 2, '.', ''));
        $this->assertSame($before, bcsub($books(), (string) $stock()->value, 2), 'a price correction leaves the inventory account equal to the stock');
    }
}
