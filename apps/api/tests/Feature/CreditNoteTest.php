<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2, credit notes: drafted for a posted invoice (its customer, currency and rate), numbered and posted on
 * approval, applied to the invoice while it owes; never more than the invoice; leftover credit applied to another
 * invoice. Roles: PermissionMatrixTest. Other companies: TenantIsolationTest. Database rules: T44.
 * The fixture invoice is 1,050.00 with 50.00 paid, so 1,000.00 is due.
 */
final class CreditNoteTest extends TestCase
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

    public function test_a_credit_note_is_posted_and_settles_part_of_its_invoice(): void
    {
        $id = $this->draft($this->c['invoice'], '200')->assertCreated()
            ->assertJsonPath('type', 'credit_note')->assertJsonPath('number', null)
            ->assertJsonPath('contact_id', $this->c['contact'])->assertJsonPath('original_document_id', $this->c['invoice'])
            ->json('id');

        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk()
            ->assertJsonPath('number', 'CN-2027-0001')->assertJsonPath('status', 'paid')->assertJsonPath('warnings', []);

        // 1,000.00 due - 200.00 credited = 800.00
        $this->assertSame(['part_paid', '800.00'], [
            $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('status'),
            (string) $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('amount_due'),
        ]);
        $entry = $this->owner()->table('documents')->where('id', $id)->value('journal_entry_id');
        $ar = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->where('account_id', $this->accountId('accounts_receivable'))->sole();
        $this->assertSame(['0.00', '200.00'], [(string) $ar->debit, (string) $ar->credit]);
        $this->getJson("/api/v1/customers/{$this->c['contact']}", $this->h)->assertJsonPath('open_balance', '800.00');
    }

    public function test_credit_notes_never_add_up_to_more_than_the_invoice(): void
    {
        $this->draft($this->c['invoice'], '1050.01')->assertUnprocessable()->assertJsonValidationErrors('lines');

        $first = $this->draft($this->c['invoice'], '1000')->json('id');
        $second = $this->draft($this->c['invoice'], '100')->json('id');
        $this->postJson("/api/v1/invoices/{$first}/approve", [], $this->h)->assertOk();
        $this->postJson("/api/v1/invoices/{$second}/approve", [], $this->h)->assertUnprocessable();   // 1,100.00 of 1,050.00
        $this->assertSame('draft', $this->owner()->table('documents')->where('id', $second)->value('status'));
    }

    public function test_credit_left_over_is_applied_to_another_invoice_of_the_customer(): void
    {
        // 1,000.00 due; a 1,050.00 credit note settles it and leaves 50.00 of credit.
        $cn = $this->draft($this->c['invoice'], '1050')->json('id');
        $this->postJson("/api/v1/invoices/{$cn}/approve", [], $this->h)->assertOk()->assertJsonPath('status', 'part_paid')->assertJsonPath('amount_due', '50.00');

        $other = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-06', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '300', 'account_id' => $this->c['sales']]]], $this->h)->json('id');
        $this->postJson("/api/v1/invoices/{$other}/approve", [], $this->h)->assertOk();

        $this->postJson("/api/v1/credit-notes/{$cn}/apply", ['invoice_id' => $other, 'amount' => '50.01'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/credit-notes/{$cn}/apply", ['invoice_id' => $other, 'amount' => '50'], $this->h)->assertOk()
            ->assertJsonPath('credit_note.status', 'paid')->assertJsonPath('invoice.amount_due', '250.00');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'credit_applied'])->count());
    }

    public function test_only_a_posted_invoice_is_credited_and_the_reason_is_required(): void
    {
        $this->draft($this->c['draft'], '10')->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->draft($this->c['credit_note'], '10')->assertNotFound();
        $this->postJson("/api/v1/invoices/{$this->c['invoice']}/credit-notes", ['issue_date' => '2026-11-05',
            'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $this->c['sales']]]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson("/api/v1/invoices/{$this->c['invoice']}/credit-notes", ['issue_date' => '2026-10-01', 'notes' => 'Before the invoice',
            'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $this->c['sales']]]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('issue_date');
    }

    private function draft(string $invoiceId, string $amount): TestResponse
    {
        return $this->postJson("/api/v1/invoices/{$invoiceId}/credit-notes", [
            'issue_date' => '2026-11-05', 'notes' => 'Two bags returned, damaged in transit',
            'lines' => [['description' => 'Coffee returned', 'quantity' => '1', 'unit_price' => $amount, 'account_id' => $this->c['sales']]],
        ], $this->h);
    }

    private function accountId(string $key): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => $key])->value('id');
    }
}
