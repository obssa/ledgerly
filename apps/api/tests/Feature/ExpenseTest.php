<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: expenses paid on the spot. One step records and posts: Dr cost (and input VAT for a VAT-registered company),
 * Cr the bank, cash or wallet it was paid from; accounts payable is never touched. A mistake is voided.
 */
final class ExpenseTest extends TestCase
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
    private function expense(array $over = [], array $line = []): array
    {
        return ['contact_id' => $this->c['supplier'], 'paid_from_account_id' => $this->account('1000'), 'payment_method' => 'cash',
            'reference' => 'R-0091', 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Electricity, October', 'quantity' => '1', 'unit_price' => '2000', 'account_id' => $this->account('6200'),
                'tax_rate_id' => $this->taxRate('VAT15'), ...$line]], ...$over];
    }

    public function test_an_expense_is_recorded_and_posted_against_the_account_it_was_paid_from(): void
    {
        $this->owner()->table('companies')->where('id', $this->c['company'])->update(['vat_registered' => true]);
        $before = $this->apBalance();

        $expense = $this->postJson('/api/v1/expenses', $this->expense(), $this->h)->assertCreated()
            ->assertJsonPath('type', 'expense')->assertJsonPath('status', 'posted')->assertJsonPath('number', 'EXP-2027-0002')
            ->assertJsonPath('total', '2300.00')->assertJsonPath('amount_due', '0.00')->assertJsonPath('paid_from.code', '1000')
            ->assertJsonPath('issue_date', '2026-11-12');

        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $expense->json('journal_entry_id'))->get()->keyBy('account_id');
        $this->assertSame('2000.00', $lines[$this->account('6200')]->debit);
        $this->assertSame('300.00', $lines[$this->account('1350')]->debit);
        $this->assertSame('2300.00', $lines[$this->account('1000')]->credit);
        $this->assertSame($before, $this->apBalance(), 'accounts payable is not touched');

        $this->getJson('/api/v1/expenses', $this->h)->assertOk()->assertJsonPath('data.0.reference', 'R-0091');
        $this->getJson("/api/v1/bills/{$expense->json('id')}", $this->h)->assertNotFound();   // not a bill
        $this->getJson('/api/v1/bills?open=1', $this->h)->assertOk()->assertJsonMissing(['id' => $expense->json('id')]);
    }

    public function test_a_mistake_is_voided_and_the_money_comes_back(): void
    {
        $id = $this->postJson('/api/v1/expenses', $this->expense(), $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/expenses/{$id}/void", ['reason' => 'Entered on the wrong account'], $this->h)->assertOk()->assertJsonPath('status', 'void');
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $this->h)->json('data'));
        $this->assertNull($tb->firstWhere('account_id', $this->account('6200')), 'the cost is gone');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'voided'])->count());
    }

    public function test_expense_rules(): void
    {
        $this->postJson('/api/v1/expenses', $this->expense(['paid_from_account_id' => $this->account('6200')]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('paid_from_account_id');
        $this->postJson('/api/v1/expenses', $this->expense(['contact_id' => $this->c['contact']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/expenses', $this->expense([], ['account_id' => $this->c['sales']]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->postJson('/api/v1/expenses', $this->expense(['issue_date' => '2099-01-01']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('issue_date');
        $this->postJson('/api/v1/expenses', $this->expense(['payment_method' => 'barter']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        // A bank account kept in dollars pays only dollar expenses.
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'USD account', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/expenses', $this->expense(['paid_from_account_id' => $usd]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('paid_from_account_id');
    }

    public function test_bookkeepers_record_expenses_viewers_only_read_them(): void
    {
        $this->actingAs($this->member('bookkeeper'), 'sanctum');
        $this->postJson('/api/v1/expenses', $this->expense(), $this->h)->assertCreated()->assertJsonPath('can.void', true);

        $this->actingAs($this->member('viewer'), 'sanctum');
        $this->getJson('/api/v1/expenses', $this->h)->assertOk();
        $this->postJson('/api/v1/expenses', $this->expense(), $this->h)->assertForbidden();
        $this->actingAs($this->member('purchasing'), 'sanctum');
        $this->getJson('/api/v1/expenses', $this->h)->assertForbidden();
    }

    private function apBalance(): string
    {
        return (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->account('2000')])
            ->selectRaw('coalesce(sum(credit - debit), 0)::text AS b')->value('b');
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
