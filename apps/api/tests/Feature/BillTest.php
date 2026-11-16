<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: suppliers and bills. A bill posts Dr cost, Dr input VAT (VAT-registered companies only), Cr accounts payable,
 * takes the company's own gapless number, keeps the supplier's reference, and warns about likely duplicates.
 */
final class BillTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Ethio Telecom', 'tax_id' => '0001234567', 'payment_terms_days' => 15,
            'payout' => ['bank_name' => 'Commercial Bank of Ethiopia', 'account_name' => 'Ethio Telecom', 'account_number' => '1000123456789']], $this->h)
            ->assertCreated()->assertJsonPath('payout.bank_name', 'Commercial Bank of Ethiopia')->json('id');
    }

    /** @return array<string, mixed> */
    private function bill(array $over = [], array $line = []): array
    {
        return [
            'contact_id' => $this->supplier, 'issue_date' => '2026-11-10', 'due_date' => '2026-11-25', 'currency' => 'ETB', 'reference' => 'ET-55821',
            'lines' => [['description' => 'Internet, November', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $this->account('6300'),
                'tax_rate_id' => $this->taxRate('VAT15'), ...$line]],
            ...$over,
        ];
    }

    public function test_a_bill_posts_cost_and_input_vat_against_accounts_payable(): void
    {
        $this->owner()->table('companies')->where('id', $this->c['company'])->update(['vat_registered' => true]);

        $draft = $this->postJson('/api/v1/bills', $this->bill(), $this->h)->assertCreated()
            ->assertJsonPath('status', 'draft')->assertJsonPath('number', null)->assertJsonPath('total', '1150.00')
            ->assertJsonPath('reference', 'ET-55821')->assertJsonPath('warnings', [])
            ->assertJsonPath('issue_date', '2026-11-10')->assertJsonPath('due_date', '2026-11-25')   // plain dates, not timestamps
            ->json('id');

        $bill = $this->postJson("/api/v1/bills/{$draft}/approve", [], $this->h)->assertOk()
            ->assertJsonPath('status', 'posted')->assertJsonPath('number', 'BILL-2027-0002')   // the fixture's bill is 0001
            ->assertJsonPath('amount_due', '1150.00');
        $this->assertSame([], $this->owner()->table('einvoice_submissions')->where('document_id', $draft)->get()->all(), 'a bill is not sent to the tax authority');

        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $bill->json('journal_entry_id'))->get()
            ->mapWithKeys(fn ($l) => [$l->account_id => [$l->debit, $l->credit]]);
        $this->assertEquals(['1000.00', '0.00'], $lines[$this->account('6300')]);
        $this->assertEquals(['150.00', '0.00'], $lines[$this->account('1350')], 'input VAT is reclaimable');
        $this->assertEquals(['0.00', '1150.00'], $lines[$this->account('2000')]);
        $this->assertSame('payable', $this->owner()->table('accounts')->where('id', $this->account('2000'))->value('subtype'));

        $this->getJson("/api/v1/suppliers/{$this->supplier}", $this->h)->assertOk()
            ->assertJsonPath('open_balance', '1150.00')->assertJsonPath('recent_bills.0.reference', 'ET-55821');
        $this->getJson('/api/v1/bills?open=1', $this->h)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_without_vat_registration_the_vat_is_part_of_the_cost(): void
    {
        $draft = $this->postJson('/api/v1/bills', $this->bill(), $this->h)->assertCreated()->json('id');
        $entry = $this->postJson("/api/v1/bills/{$draft}/approve", [], $this->h)->assertOk()->json('journal_entry_id');

        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get()->keyBy('account_id');
        $this->assertSame('1150.00', $lines[$this->account('6300')]->debit);
        $this->assertArrayNotHasKey($this->account('1350'), $lines->all());
    }

    public function test_a_likely_duplicate_is_flagged_but_not_blocked(): void
    {
        $this->postJson('/api/v1/bills', $this->bill(), $this->h)->assertCreated();
        $second = $this->postJson('/api/v1/bills', $this->bill(['reference' => 'et-55821']), $this->h)->assertCreated()
            ->assertJsonPath('warnings.0.type', 'possible_duplicate');
        $this->assertStringContainsString('same reference', $second->json('warnings.0.message'));

        // No reference, but the same total on the same date.
        $this->postJson('/api/v1/bills', $this->bill(['reference' => null]), $this->h)->assertCreated()
            ->assertJsonPath('warnings.0.type', 'possible_duplicate');
        $this->postJson("/api/v1/bills/{$second->json('id')}/approve", [], $this->h)->assertOk();
    }

    public function test_bills_name_suppliers_and_costs_and_stay_apart_from_invoices(): void
    {
        $this->postJson('/api/v1/bills', $this->bill(['contact_id' => $this->c['contact']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/bills', $this->bill([], ['account_id' => $this->c['sales']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->postJson('/api/v1/bills', $this->bill([], ['account_id' => $this->account('1200')]), $this->h)->assertUnprocessable();  // receivable
        $this->postJson('/api/v1/bills', $this->bill([], ['item_id' => $this->c['item']]), $this->h)->assertUnprocessable();
        $this->postJson('/api/v1/bills', $this->bill(['number' => 'B-1']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('number');

        // One table, but each route finds only its own kind.
        $this->getJson("/api/v1/invoices/{$this->c['bill']}", $this->h)->assertNotFound();
        $this->postJson("/api/v1/invoices/{$this->c['bill']}/approve", [], $this->h)->assertNotFound();
        $this->getJson("/api/v1/bills/{$this->c['invoice']}", $this->h)->assertNotFound();
        $this->getJson('/api/v1/bills/not-a-uuid', $this->h)->assertNotFound();
        $this->getJson('/api/v1/invoices', $this->h)->assertOk()->assertJsonMissing(['id' => $this->c['bill']]);
        $this->getJson("/api/v1/suppliers/{$this->c['contact']}", $this->h)->assertNotFound();   // a customer only
    }

    public function test_a_posted_bill_is_edited_with_history_or_voided(): void
    {
        $id = $this->c['posted_bill'];
        $version = $this->getJson("/api/v1/bills/{$id}", $this->h)->assertOk()->json('lock_version');
        $this->putJson("/api/v1/bills/{$id}", $this->bill(['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-05', 'reference' => 'R-100', 'lock_version' => $version],
            ['unit_price' => '450', 'tax_rate_id' => null, 'account_id' => $this->c['rent']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->putJson("/api/v1/bills/{$id}", [...$this->bill(['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-05', 'reference' => 'R-100', 'lock_version' => $version],
            ['unit_price' => '450', 'tax_rate_id' => null, 'account_id' => $this->c['rent']]), 'reason' => 'Supplier corrected the amount'], $this->h)
            ->assertOk()->assertJsonPath('total', '450.00')->assertJsonPath('number', 'BILL-2027-0001');
        $this->getJson("/api/v1/bills/{$id}/history", $this->h)->assertOk()->assertJsonCount(1, 'versions');

        $this->postJson("/api/v1/bills/{$id}/void", ['reason' => 'Entered twice'], $this->h)->assertOk()->assertJsonPath('status', 'void');
        $this->getJson("/api/v1/suppliers/{$this->c['supplier']}", $this->h)->assertJsonPath('open_balance', '0.00');
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $this->h)->json('data'));
        $this->assertSame('90.00', $tb->firstWhere('account_id', $this->account('2000'))['debit'], 'once the only posted bill is void, only the fixture\'s advance is left (10.00 refunded)');
    }

    public function test_purchasing_staff_enter_bills_and_an_approver_posts_them(): void
    {
        $clerk = $this->member('purchasing');
        $this->actingAs($clerk, 'sanctum');
        $draft = $this->postJson('/api/v1/bills', $this->bill(), $this->h)->assertCreated()->assertJsonPath('can.approve', false)->json('id');
        $this->postJson("/api/v1/bills/{$draft}/submit", [], $this->h)->assertOk()->assertJsonPath('status', 'awaiting_approval');
        $this->postJson("/api/v1/bills/{$draft}/approve", [], $this->h)->assertForbidden();

        $this->actingAs($this->member('approver'), 'sanctum');
        $this->postJson("/api/v1/bills/{$draft}/approve", [], $this->h)->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $draft, 'action' => 'submitted_for_approval'])->count());
    }

    public function test_supplier_rules(): void
    {
        $this->postJson('/api/v1/suppliers', ['name' => 'No certificate', 'withholding_exempt' => true], $this->h)->assertUnprocessable()->assertJsonValidationErrors('withholding_exempt_reason');
        $this->postJson('/api/v1/suppliers', ['name' => 'Wrong default', 'default_expense_account_id' => $this->c['sales']], $this->h)->assertUnprocessable();
        $this->postJson('/api/v1/suppliers', ['name' => 'Bad TIN', 'tax_id' => '12'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/v1/suppliers', ['name' => 'Odd payout', 'payout' => ['swift' => 'X']], $this->h)->assertUnprocessable();

        $this->putJson("/api/v1/suppliers/{$this->supplier}", ['payout' => ['bank_name' => 'Dashen Bank', 'account_number' => '5000999']], $this->h)
            ->assertOk()->assertJsonPath('payout.bank_name', 'Dashen Bank');
        $audit = $this->owner()->table('audit_log')->where(['subject_id' => $this->supplier, 'action' => 'payout_changed'])->first();
        $this->assertNotNull($audit, 'a change of payout details is audited as such');
        $this->assertStringContainsString('Commercial Bank of Ethiopia', (string) $audit->before);

        $this->putJson("/api/v1/suppliers/{$this->supplier}", ['withholding_exempt' => true, 'withholding_exempt_reason' => 'WHT-EX-2027-114', 'default_expense_account_id' => $this->account('6300')], $this->h)
            ->assertOk()->assertJsonPath('withholding_exempt', true);
        $this->putJson("/api/v1/suppliers/{$this->supplier}", ['is_active' => false], $this->h)->assertOk();
        $this->getJson('/api/v1/suppliers', $this->h)->assertOk()->assertJsonMissing(['id' => $this->supplier]);
        $this->getJson('/api/v1/suppliers?include_archived=1&search=telecom', $this->h)->assertOk()->assertJsonPath('data.0.id', $this->supplier);
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

    private function taxRate(string $code): string
    {
        return $this->owner()->table('tax_rates')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }
}
