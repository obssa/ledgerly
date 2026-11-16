<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: supplier credits. A credit on a posted bill posts Dr payable, Cr cost (and VAT receivable), settles what the
 * bill still costs on approval, never credits more than the bill, and leftover credit goes to another open bill.
 */
final class SupplierCreditTest extends TestCase
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

    /** @return array<string, mixed> */
    private function credit(string $price, array $over = []): array
    {
        return ['issue_date' => '2026-11-08', 'reference' => 'RC-2', 'notes' => 'Two days of rent refunded',
            'lines' => [['description' => 'Rent refund', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $this->c['rent']]], ...$over];
    }

    public function test_a_supplier_credit_posts_against_payable_and_settles_its_bill(): void
    {
        // The fixture's draft credit of 40.00 on the posted rent bill of 400.00.
        $credit = $this->getJson("/api/v1/bills/{$this->c['supplier_credit']}", $this->h)->assertOk()
            ->assertJsonPath('type', 'vendor_credit')->assertJsonPath('original.number', 'BILL-2027-0001')->assertJsonPath('total', '40.00');
        $posted = $this->postJson("/api/v1/bills/{$this->c['supplier_credit']}/approve", [], $this->h)->assertOk()
            ->assertJsonPath('number', 'VC-2027-0001')->assertJsonPath('status', 'paid')->assertJsonPath('amount_due', '0.00');

        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $posted->json('journal_entry_id'))->get()->keyBy('account_id');
        $this->assertSame('40.00', $lines[$this->account('2000')]->debit);
        $this->assertSame('40.00', $lines[$this->c['rent']]->credit);
        $this->getJson("/api/v1/bills/{$this->c['posted_bill']}", $this->h)->assertJsonPath('status', 'part_paid')->assertJsonPath('amount_due', '360.00');
        $this->getJson('/api/v1/supplier-credits', $this->h)->assertOk()->assertJsonPath('data.0.original.number', 'BILL-2027-0001');
        $this->getJson('/api/v1/bills', $this->h)->assertOk()->assertJsonMissing(['id' => $credit->json('id')]);   // bills only
    }

    public function test_credits_never_add_up_to_more_than_the_bill_and_leftover_goes_to_another_bill(): void
    {
        $this->postJson("/api/v1/bills/{$this->c['posted_bill']}/credits", $this->credit('400.01'), $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson("/api/v1/bills/{$this->c['bill']}/credits", $this->credit('10'), $this->h)->assertUnprocessable();   // a draft bill
        $this->postJson("/api/v1/bills/{$this->c['posted_bill']}/credits", $this->credit('10', ['notes' => '']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('notes');

        // Pay the bill in full, then credit 100.00 of it: nothing left to settle, so all of it is credit to use later.
        $this->postJson('/api/v1/payments-made', ['contact_id' => $this->c['supplier'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-07',
            'method' => 'bank_transfer', 'currency' => 'ETB', 'amount' => '400', 'allocations' => [['bill_id' => $this->c['posted_bill'], 'amount' => '400']]], $this->h)->assertCreated();
        $id = $this->postJson("/api/v1/bills/{$this->c['posted_bill']}/credits", $this->credit('100'), $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$id}/approve", [], $this->h)->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('amount_due', '100.00');

        // The draft bill (400.00), posted, takes 60.00 of it.
        $this->postJson("/api/v1/bills/{$this->c['bill']}/approve", [], $this->h)->assertOk();
        $this->postJson("/api/v1/supplier-credits/{$id}/apply", ['bill_id' => $this->c['bill'], 'amount' => '60'], $this->h)->assertOk()
            ->assertJsonPath('credit.amount_due', '40.00')->assertJsonPath('bill.amount_due', '340.00');
        $this->postJson("/api/v1/supplier-credits/{$id}/apply", ['bill_id' => $this->c['bill'], 'amount' => '40.01'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/supplier-credits/{$id}/apply", ['bill_id' => $this->c['invoice'], 'amount' => '1'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('bill_id');
        $this->postJson("/api/v1/supplier-credits/{$this->c['bill']}/apply", ['bill_id' => $this->c['bill'], 'amount' => '1'], $this->h)->assertNotFound();   // a bill is not a credit

        // A posted credit is not voided.
        $this->postJson("/api/v1/bills/{$id}/void", ['reason' => 'Changed my mind'], $this->h)->assertUnprocessable();
    }

    public function test_invoice_routes_do_not_reach_supplier_credits(): void
    {
        $this->getJson("/api/v1/invoices/{$this->c['supplier_credit']}", $this->h)->assertNotFound();
        $this->postJson("/api/v1/credit-notes/{$this->c['supplier_credit']}/apply", ['invoice_id' => $this->c['invoice'], 'amount' => '1'], $this->h)->assertNotFound();
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
