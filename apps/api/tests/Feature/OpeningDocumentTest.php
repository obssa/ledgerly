<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1: open invoices and bills from the old books (057). Each keeps its number and dates, owes its open amount,
 * posts on the conversion date against opening balance equity (no income, cost or tax), is never sent to the tax
 * authority, is paid like any other, and is voided (not edited) to correct. Another company's contacts are not found.
 */
final class OpeningDocumentTest extends TestCase
{
    use RefreshLedgerDatabase;

    public function test_open_invoices_and_bills_come_in_at_the_conversion_date_against_opening_balance_equity(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->actingAs($this->createUser('Selam', 'selam@example.test'), 'sanctum');
        $b = $this->postJson('/api/v1/companies', ['legal_name' => 'Blue Nile Traders', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->assertCreated()->json('id');
        $bCustomer = $this->postJson('/api/v1/customers', ['name' => 'B customer'], ['X-Company-Id' => $b])->assertCreated()->json('id');

        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
        $c = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->assertCreated()->json('id');
        $h = ['X-Company-Id' => $c];
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Kaldi Cafe'], $h)->assertCreated()->json('id');
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Addis Packaging'], $h)->assertCreated()->json('id');
        $invoice = fn (array $over = []) => [...['contact_id' => $customer, 'number' => 'OLD-17', 'issue_date' => '2026-06-10', 'due_date' => '2026-07-10',
            'currency' => 'ETB', 'amount' => '11,500.00'], ...$over];

        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '11500']), $h)->assertUnprocessable()->assertJsonValidationErrors('conversion_date');
        $version = $this->getJson('/api/v1/company', $h)->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'conversion_date' => '2026-07-07'], $h)->assertOk();

        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['contact_id' => $bCustomer, 'amount' => '11500']), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['contact_id' => $supplier, 'amount' => '11500']), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '11500', 'issue_date' => '2026-07-08', 'due_date' => null]), $h)->assertUnprocessable()->assertJsonValidationErrors('issue_date');
        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '11500', 'number' => 'INV-0001']), $h)->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '0']), $h)->assertUnprocessable()->assertJsonValidationErrors('amount');

        $made = $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '11500']), $h)->assertCreated()
            ->assertJsonPath('number', 'OLD-17')->assertJsonPath('amount_due', '11500.00')->json();
        $this->postJson('/api/v1/opening-balances/invoices', $invoice(['amount' => '100']), $h)->assertUnprocessable()->assertJsonValidationErrors('number');   // used

        $entry = $this->owner()->table('journal_entries')->where('id', $made['journal_entry_id'])->first();
        $this->assertSame('2026-07-07', (string) $entry->entry_date);
        $account = fn (string $key) => $this->owner()->table('accounts')->where(['company_id' => $c, 'system_key' => $key])->value('id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame('11500.00', (string) $lines->firstWhere('account_id', $account('accounts_receivable'))->debit);
        $this->assertSame($customer, (string) $lines->firstWhere('account_id', $account('accounts_receivable'))->contact_id);
        $this->assertSame('11500.00', (string) $lines->firstWhere('account_id', $account('opening_balance_equity'))->credit);
        $this->assertSame(0, $this->owner()->table('einvoice_submissions')->where('document_id', $made['id'])->count(), 'not sent to the tax authority again');
        $this->getJson("/api/v1/invoices/{$made['id']}", $h)->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('opening', true)
            ->assertJsonPath('issue_date', '2026-06-10')->assertJsonPath('due_date', '2026-07-10');

        // A bill: numbered as ours, the supplier's number kept; Dr opening balance equity, Cr accounts payable.
        $bill = $this->postJson('/api/v1/opening-balances/bills', ['contact_id' => $supplier, 'reference' => 'AP-55', 'issue_date' => '2026-06-20',
            'currency' => 'ETB', 'amount' => '4000'], $h)->assertCreated()->assertJsonPath('reference', 'AP-55')->json();
        $this->assertMatchesRegularExpression('/^BILL-/', $bill['number']);
        $this->assertSame('4000.00', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $bill['journal_entry_id'], 'account_id' => $account('accounts_payable')])->value('credit'));
        $this->getJson('/api/v1/opening-balances', $h)->assertOk()->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.type', 'bill')->assertJsonPath('documents.1.number', 'OLD-17')->assertJsonPath('documents.1.contact.name', 'Kaldi Cafe');

        // Paid like any other invoice; corrected by voiding, never by an edit.
        $this->putJson("/api/v1/invoices/{$made['id']}", ['contact_id' => $customer, 'issue_date' => '2026-06-10', 'currency' => 'ETB', 'reason' => 'Wrong amount',
            'lines' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $account('sales')]]], $h)->assertUnprocessable();
        $this->postJson('/api/v1/payments-received', ['contact_id' => $customer, 'account_id' => $account('bank'), 'payment_date' => '2026-07-15', 'method' => 'cash',
            'currency' => 'ETB', 'amount' => '1500', 'allocations' => [['invoice_id' => $made['id'], 'amount' => '1500']]], $h)->assertCreated();
        $this->getJson("/api/v1/invoices/{$made['id']}", $h)->assertJsonPath('status', 'part_paid')->assertJsonPath('amount_due', '10000.00');
        $this->postJson("/api/v1/bills/{$bill['id']}/void", ['reason' => 'Entered twice'], $h)->assertOk();
        $this->getJson('/api/v1/opening-balances', $h)->assertJsonCount(1, 'documents');

        $this->assertSame(2, $this->owner()->table('audit_log')->where(['company_id' => $c, 'action' => 'opening_document_entered'])->count());

        // Many at once from a spreadsheet: customers by name, dates day first; one bad row and none come in.
        $csv = "Customer,Invoice number,Date,Due date,Amount\nKaldi Cafe,OLD-18,15/06/2026,15/07/2026,\"2,500.00\"\nNobody Ltd,OLD-19,2026-06-16,,700\nB customer,OLD-20,2026-06-16,,700\n";
        $checked = $this->postJson('/api/v1/imports/open-invoices', ['csv' => $csv, 'check' => true], $h)->assertOk()->assertJsonPath('added', 0);
        $this->assertSame([3, 4], array_column($checked->json('errors'), 'row'));   // unknown here, and company B's customer is not found
        $this->postJson('/api/v1/imports/open-invoices', ['csv' => "Customer,Number\nKaldi Cafe,OLD-21\n"], $h)->assertUnprocessable()->assertJsonValidationErrors('csv');
        $this->postJson('/api/v1/imports/open-invoices', ['csv' => "Customer,Invoice number,Date,Due date,Amount\nKaldi Cafe,OLD-18,15/06/2026,15/07/2026,\"2,500.00\"\n"], $h)
            ->assertCreated()->assertJsonPath('added', 1);
        $this->assertSame(['2026-06-15', '2026-07-15', '2500.00'], array_values((array) $this->owner()->table('documents')
            ->where(['company_id' => $c, 'number' => 'OLD-18'])->first(['issue_date', 'due_date', 'amount_due'])));
        $this->postJson('/api/v1/imports/open-bills', ['csv' => "Supplier,Supplier invoice number,Date,Amount\nAddis Packaging,AP-56,2026-06-21,900\n"], $h)
            ->assertCreated()->assertJsonPath('added', 1);
        $this->getJson('/api/v1/opening-balances', $h)->assertJsonCount(3, 'documents');
    }
}
