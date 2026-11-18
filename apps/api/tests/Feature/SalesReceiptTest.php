<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: sales receipts, the cash sale (070). A sale paid at once is recorded and posted in one step: Dr the money
 * account it went into, Cr income and VAT payable; stock items leave at their cost (Dr Cost of goods sold, Cr
 * Inventory). Nothing is owed and accounts receivable is never touched. Voided, the entry is reversed and the goods
 * come back. Only a customer, an income account and one of the company's own money accounts are accepted, and
 * another company never sees it.
 */
final class SalesReceiptTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_cash_sale_posts_to_the_money_account_and_takes_the_goods_out(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $system = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $vat = (string) $this->owner()->table('tax_rates')->where(['company_id' => $c['company'], 'kind' => 'vat'])->where('rate', '0.15')->value('id');
        $this->assertNotSame('', $vat);
        $item = fn () => collect($this->getJson('/api/v1/items', $h)->assertOk()->json('data'))->firstWhere('id', $c['stock_item']);
        $sale = fn (array $over = []) => [...['contact_id' => $c['contact'], 'paid_from_account_id' => $c['bank'], 'payment_method' => 'mobile_money',
            'reference' => 'TB-88231', 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lines' => [
                ['description' => 'Roasted coffee 1 kg', 'quantity' => '2', 'unit_price' => '1150', 'account_id' => $c['sales'], 'tax_rate_id' => $vat, 'item_id' => $c['stock_item']],
            ]], ...$over];

        // Only a customer, an income account and a money account of the company's own.
        $this->postJson('/api/v1/sales-receipts', $sale(['contact_id' => $c['supplier']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/sales-receipts', $sale(['paid_from_account_id' => $c['sales']]), $h)->assertUnprocessable()->assertJsonValidationErrors('paid_from_account_id');
        $this->postJson('/api/v1/sales-receipts', $sale(['paid_from_account_id' => $b['bank']]), $h)->assertUnprocessable()->assertJsonValidationErrors('paid_from_account_id');
        $this->postJson('/api/v1/sales-receipts', $sale(['lines' => [['description' => 'Rent', 'quantity' => '1', 'unit_price' => '10', 'account_id' => $c['rent']]]]), $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');

        // 2 bags at 1,150.00 + 15% VAT = 2,645.00, received on telebirr into the bank account.
        $made = $this->postJson('/api/v1/sales-receipts', $sale(), $h)->assertCreated()->assertJsonPath('type', 'sales_receipt')
            ->assertJsonPath('status', 'posted')->assertJsonPath('total', '2645.00')->assertJsonPath('amount_due', '0.00')
            ->assertJsonPath('paid_from.id', $c['bank'])->assertJsonPath('payment_method', 'mobile_money')->assertJsonPath('can.void', true)->json();
        $this->assertMatchesRegularExpression('/^SR-/', $made['number']);
        $this->assertSame('18.00', $item()['on_hand']);

        $lines = $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'journal_entry_id' => $made['journal_entry_id']])
            ->get(['account_id', 'debit', 'credit']);
        $net = fn (string $account) => $lines->where('account_id', $account)->reduce(fn (string $s, $l) => bcadd($s, bcsub((string) $l->debit, (string) $l->credit, 2), 2), '0.00');
        $this->assertSame('2645.00', $net($c['bank']));
        $this->assertSame('-2300.00', $net($c['sales']));
        $this->assertSame('-345.00', $net($system('vat_payable')));
        $this->assertSame('100.00', $net($system('cogs')));
        $this->assertSame('-100.00', $net($system('inventory')));
        $this->assertSame('0.00', $net($system('accounts_receivable')), 'a cash sale never passes through receivables');
        $this->assertSame('sales_receipt', $this->owner()->table('journal_entries')->where('id', $made['journal_entry_id'])->value('source_type'));

        $this->assertContains($made['id'], array_column($this->getJson('/api/v1/sales-receipts', $h)->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/sales-receipts/{$made['id']}", $h)->assertOk()->assertJsonPath('reference', 'TB-88231')->assertJsonPath('lines.0.quantity', '2.00');
        $this->getJson("/api/v1/invoices/{$made['id']}", $h)->assertNotFound();   // its own routes only

        // Another company finds none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/sales-receipts/{$made['id']}", $hb)->assertNotFound();
        $this->postJson("/api/v1/sales-receipts/{$made['id']}/void", ['reason' => 'Not ours'], $hb)->assertNotFound();
        $this->assertNotContains($made['id'], array_column($this->getJson('/api/v1/sales-receipts', $hb)->assertOk()->json('data'), 'id'));
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // Voided: the entry is reversed, the bags come back, the number stays.
        $this->postJson("/api/v1/sales-receipts/{$made['id']}/void", [], $h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/sales-receipts/{$made['id']}/void", ['reason' => 'Rang up the wrong customer'], $h)->assertOk()
            ->assertJsonPath('status', 'void')->assertJsonPath('number', $made['number'])->assertJsonPath('can.void', false);
        $this->assertSame('20.00', $item()['on_hand']);
        $this->assertSame('reversed', $this->owner()->table('journal_entries')->where('id', $made['journal_entry_id'])->value('status'));
        $this->assertContains('voided', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $made['id']])->pluck('action')->all());
    }
}
