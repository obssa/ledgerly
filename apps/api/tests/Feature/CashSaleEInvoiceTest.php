<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2 (082): a cash sale is registered with the tax authority like an invoice. Registered, it is not voided: it is
 * corrected with a credit note, which brings the goods back and, the sale having been paid, stays as the customer's
 * credit. The credit notes of a cash sale never add up to more than it, and another company finds none of it.
 */
final class CashSaleEInvoiceTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_cash_sale_is_registered_and_corrected_with_a_credit_note(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $stock = fn () => (string) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'], 'warehouse_id' => $c['store']])
            ->selectRaw('quantity::numeric(18,2)::text AS q')->value('q');
        $line = fn (string $qty) => ['description' => 'Coffee bags', 'quantity' => $qty, 'unit_price' => '100', 'account_id' => $c['sales'], 'item_id' => $c['stock_item']];

        $sale = $this->postJson('/api/v1/sales-receipts', ['contact_id' => $c['contact'], 'paid_from_account_id' => $c['bank'], 'payment_method' => 'cash',
            'issue_date' => '2026-11-12', 'currency' => 'ETB', 'warehouse_id' => $c['store'], 'lines' => [$line('2')]], $h)
            ->assertCreated()->assertJsonPath('total', '200.00')->json('id');
        $this->assertSame('18.00', $stock());

        // Registered like an invoice.
        $submission = $this->owner()->table('einvoice_submissions')->where('document_id', $sale)->first();
        $this->assertNotNull($submission);
        $this->assertSame('cleared', $submission->status);
        $this->getJson("/api/v1/sales-receipts/{$sale}", $h)->assertOk()->assertJsonPath('einvoice_submissions.0.status', 'cleared')->assertJsonPath('can.credit', true);

        // Registered, it is not voided: a credit note corrects it.
        $this->postJson("/api/v1/sales-receipts/{$sale}/void", ['reason' => 'Rang up twice'], $h)->assertUnprocessable();
        $credit = $this->postJson("/api/v1/sales-receipts/{$sale}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'One bag returned',
            'restock' => true, 'lines' => [$line('1')]], $h)->assertCreated()->assertJsonPath('type', 'credit_note')->assertJsonPath('original_document_id', $sale)->json('id');
        $this->postJson("/api/v1/invoices/{$credit}/approve", [], $h)->assertOk()->assertJsonPath('amount_due', '100.00');   // the customer's credit
        $this->assertSame('19.00', $stock());
        $this->assertSame('cleared', $this->owner()->table('einvoice_submissions')->where('document_id', $credit)->value('status'));
        $this->assertNotSame('void', (string) $this->owner()->table('documents')->where('id', $sale)->value('status'));   // the sale stands, as registered

        // Never more than the sale: one bag (100.00) is left to credit.
        $this->postJson("/api/v1/sales-receipts/{$sale}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'Two more bags', 'lines' => [$line('2')]], $h)
            ->assertUnprocessable();

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/sales-receipts/{$sale}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'Not ours', 'lines' => [$line('1')]], ['X-Company-Id' => $b['company']])
            ->assertNotFound();
    }
}
