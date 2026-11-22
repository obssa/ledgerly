<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 4: stock on bills and invoices. A bill brings goods in at their cost (Dr Inventory), an invoice takes them out
 * at weighted average (Dr Cost of goods sold, Cr Inventory), a void undoes it, and through all of it the inventory account
 * equals the value of the stock on hand. The fixture holds 20 bags at 50.00 in the main store.
 */
final class StockDocumentsTest extends TestCase
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

    private function bill(string $qty, string $price, ?string $tax = null): string
    {
        $id = $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-08', 'currency' => 'ETB', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => $qty, 'unit_price' => $price, 'account_id' => $this->c['rent'],
                'item_id' => $this->c['stock_item'], 'tax_rate_id' => $tax]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$id}/approve", [], $this->h)->assertOk();

        return $id;
    }

    private function invoice(string $qty, ?string $warehouse = 'store'): string
    {
        return $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09', 'currency' => 'ETB',
            'warehouse_id' => $warehouse ? $this->c[$warehouse] : null,
            'lines' => [['description' => 'Roasted coffee', 'quantity' => $qty, 'unit_price' => '1150', 'account_id' => $this->c['sales'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertCreated()->json('id');
    }

    public function test_a_posted_invoice_with_stock_is_edited_and_the_stock_follows(): void
    {
        config(['einvoicing.force_sandbox' => false]);   // not registered, so it can be edited
        $this->app->forgetInstance(\App\Domain\Compliance\ConnectorRegistry::class);   // built with the sandbox during the fixtures
        $id = $this->invoice('5');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();
        $this->assertSame(['15.00', '750.00'], $this->onHand());
        $this->getJson("/api/v1/invoices/{$id}", $this->h)->assertOk()->assertJsonPath('correct_blocked', null)->assertJsonPath('can.correct', true);

        // 5 sold becomes 8 sold: three more bags leave, at their cost.
        $this->putJson("/api/v1/invoices/{$id}", ['reason' => 'Eight bags were delivered, not five', 'contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09',
            'currency' => 'ETB', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '8', 'unit_price' => '1150', 'account_id' => $this->c['sales'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertOk()->assertJsonPath('total', '9200.00');
        $this->assertSame(['12.00', '600.00'], $this->onHand());
        $this->assertSame('600.00', $this->balance('1300'), 'the inventory account equals the stock on hand');
        $this->assertSame([], $this->owner()->select('select * from ledger_verify(?)', [$this->c['company']]));

        // And back down to 2: six bags return.
        $version = $this->getJson("/api/v1/invoices/{$id}", $this->h)->json('lock_version');
        $this->putJson("/api/v1/invoices/{$id}", ['reason' => 'Only two in the end', 'lock_version' => $version, 'contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09',
            'currency' => 'ETB', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '2', 'unit_price' => '1150', 'account_id' => $this->c['sales'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertOk()->assertJsonPath('total', '2300.00');
        $this->assertSame(['18.00', '900.00'], $this->onHand());
        $this->assertSame('900.00', $this->balance('1300'));
    }

    public function test_bought_on_a_bill_and_sold_on_an_invoice_the_ledger_follows_the_stock(): void
    {
        // 10 more at 60.00 + VAT; the company is not VAT-registered, so the VAT is part of the cost: 690.00.
        $bill = $this->bill('10', '60', $this->taxRate('VAT15'));
        $this->assertSame(['30.00', '1690.00'], $this->onHand());
        $this->assertSame('1690.00', $this->balance('1300'), 'the bill went to inventory, not to the expense account on the line');
        $this->assertSame('0.00', $this->balance('6100', $bill));

        // 12 sold: out at the average of 1,690.00 / 30, cost 676.00 in the invoice's own entry. (No sandbox clearance, so it can be voided.)
        config(['einvoicing.force_sandbox' => false]);
        $this->app->forgetInstance(\App\Domain\Compliance\ConnectorRegistry::class);   // built with the sandbox during the fixtures
        $invoice = $this->invoice('12');
        $entry = $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $this->h)->assertOk()->json('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get();
        $this->assertSame('676.00', $lines->firstWhere('account_id', $this->account('5000'))->debit);
        $this->assertSame('676.00', $lines->firstWhere('account_id', $this->account('1300'))->credit);
        $this->assertSame(['18.00', '1014.00'], $this->onHand());
        $this->assertSame('1014.00', $this->balance('1300'));

        // Void: the 12 come back at the cost they left with, and the cost of goods sold is reversed.
        $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Sent to the wrong customer'], $this->h)->assertOk();
        $this->assertSame(['30.00', '1690.00'], $this->onHand());
        $this->assertSame('1690.00', $this->balance('1300'));
    }

    public function test_a_reclaimable_vat_is_not_part_of_the_cost(): void
    {
        $this->owner()->table('companies')->where('id', $this->c['company'])->update(['vat_registered' => true]);
        $this->bill('10', '60', $this->taxRate('VAT15'));
        $this->assertSame(['30.00', '1600.00'], $this->onHand());
    }

    public function test_stock_that_is_not_there_cannot_be_sold_and_a_warehouse_is_needed(): void
    {
        $short = $this->invoice('21');
        $this->postJson("/api/v1/invoices/{$short}/approve", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->getJson("/api/v1/invoices/{$short}", $this->h)->assertJsonPath('status', 'draft');
        $this->assertSame(['20.00', '1000.00'], $this->onHand(), 'nothing moved: the approval was refused as a whole');

        $nowhere = $this->invoice('1', null);
        $this->postJson("/api/v1/invoices/{$nowhere}/approve", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        // A service on an invoice moves no stock.
        $service = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09', 'currency' => 'ETB',
            'lines' => [['description' => 'Cupping', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->c['sales'], 'item_id' => $this->c['item']]]], $this->h)->json('id');
        $this->postJson("/api/v1/invoices/{$service}/approve", [], $this->h)->assertOk();
        $this->assertSame(['20.00', '1000.00'], $this->onHand());
    }

    public function test_voiding_a_bill_takes_the_goods_out_at_todays_average_and_books_the_difference(): void
    {
        // 10 at 80.00: 30 on hand worth 1,800.00, an average of 60.00.
        $bill = $this->bill('10', '80');
        $this->postJson("/api/v1/bills/{$bill}/void", ['reason' => 'Goods sent back'], $this->h)->assertOk();
        // Out at 60.00 (600.00) while the bill had put 800.00 on the account: 200.00 back to the account as an adjustment.
        $this->assertSame(['20.00', '1200.00'], $this->onHand());
        $this->assertSame('1200.00', $this->balance('1300'), 'the inventory account still equals the stock on hand');
    }

    public function test_a_bill_whose_goods_were_sold_is_not_voided_and_stock_documents_are_not_edited(): void
    {
        $bill = $this->bill('10', '80');
        $invoice = $this->invoice('25');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $this->h)->assertOk();
        $this->postJson("/api/v1/bills/{$bill}/void", ['reason' => 'Too late'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $version = $this->getJson("/api/v1/invoices/{$invoice}", $this->h)->json('lock_version');
        $this->putJson("/api/v1/invoices/{$invoice}", ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09', 'currency' => 'ETB', 'lock_version' => $version,
            'reason' => 'Price changed', 'warehouse_id' => $this->c['store'],
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '25', 'unit_price' => '1100', 'account_id' => $this->c['sales'], 'item_id' => $this->c['stock_item']]]], $this->h)
            ->assertUnprocessable();
    }

    /** @return array{0: string, 1: string} quantity and value on hand in the main store */
    private function onHand(): array
    {
        $b = $this->owner()->table('stock_balances')->where(['item_id' => $this->c['stock_item'], 'warehouse_id' => $this->c['store']])->first();

        return [(string) $b->quantity, (string) $b->value];
    }

    private function balance(string $code, ?string $onlyDocument = null): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->account($code)])
            ->when($onlyDocument, fn ($q, $doc) => $q->whereIn('journal_entry_id', $this->owner()->table('journal_entries')->where('source_id', $doc)->pluck('id')))
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }

    private function taxRate(string $code): string
    {
        return $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
