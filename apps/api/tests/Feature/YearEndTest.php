<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6: the year-end close. A company converted on 1 Jan 2025 with a calendar-year fiscal year; in 2025 it sold
 * 10,000.00 and spent 3,500.00, a profit of 6,500.00 that closing moves to retained earnings.
 */
final class YearEndTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private string $company;

    private array $h;

    /** @var array<string, string> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $user = $this->createUser('Owner', 'owner@example.test');
        $this->actingAs($user, 'sanctum');
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Year End Trading', 'country_code' => 'ET', 'fiscal_year_start_month' => 1,
            'conversion_date' => '2025-01-01'])->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
        foreach (['bank' => 'bank', 'sales' => 'sales', 'retained' => 'retained_earnings', 'equity' => 'opening_balance_equity'] as $k => $key) {
            $this->a[$k] = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => $key])->value('id');
        }
        $this->a['rent'] = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'code' => '6100'])->value('id');
        $post = fn (string $date, string $memo, string $dr, string $cr, string $amount) => $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => $memo, 'lines' => [
            ['account_id' => $dr, 'debit' => $amount], ['account_id' => $cr, 'credit' => $amount]]], $this->h)->assertCreated();
        $post('2025-03-10', 'Coffee sold for cash', $this->a['bank'], $this->a['sales'], '10000');
        $post('2025-06-30', 'Rent for the year', $this->a['rent'], $this->a['bank'], '3500');
    }

    private function year(string $starts): array
    {
        return collect($this->getJson('/api/v1/fiscal-years', $this->h)->assertOk()->json('data'))->firstWhere('starts_on', $starts);
    }

    public function test_closing_moves_the_profit_to_retained_earnings_and_opens_the_next_year(): void
    {
        $year = $this->year('2025-01-01');
        $this->assertSame(['open', true, false], [$year['status'], $year['can_close'], $year['can_reopen']]);
        $this->getJson("/api/v1/fiscal-years/{$year['id']}/closing", $this->h)->assertOk()->assertJsonPath('net_profit', '6500.00')->assertJsonCount(2, 'lines');

        $this->postJson("/api/v1/fiscal-years/{$year['id']}/close", [], $this->h)->assertCreated()->assertJsonPath('net_profit', '6500.00');
        $this->assertSame('closed', $this->year('2025-01-01')['status']);
        $this->assertSame('open', $this->year('2026-01-01')['status'], 'the next year opened');
        $this->assertSame('2025-12-31', $this->getJson('/api/v1/company', $this->h)->json('books_closed_until'));

        // Retained earnings hold the profit; income and expenses start 2026 at nothing; the 2025 P&L still shows it.
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2025-12-31', $this->h)->assertOk()->json('data'))->keyBy('account_id');
        $this->assertSame('6500.00', $tb[$this->a['retained']]['credit']);
        $this->assertArrayNotHasKey($this->a['sales'], $tb->all());
        $this->getJson('/api/v1/reports/profit-and-loss?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()->assertJsonPath('totals.net_profit.current', '6500.00');
        $this->getJson('/api/v1/reports/balance-sheet?as_of=2025-12-31', $this->h)->assertOk()->assertJsonPath('equation.balanced', true);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $year['id'], 'action' => 'year_closed'])->count());

        // Closed is closed: not twice, and nothing more posts into it.
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/close", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson('/api/v1/journal-entries', ['date' => '2025-12-15', 'memo' => 'Late rent', 'lines' => [
            ['account_id' => $this->a['rent'], 'debit' => '10'], ['account_id' => $this->a['bank'], 'credit' => '10']]], $this->h)->assertUnprocessable();
    }

    public function test_the_latest_closed_year_reopens_with_a_reason_and_closes_again(): void
    {
        $year = $this->year('2025-01-01');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/close", [], $this->h)->assertCreated();
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/reopen", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/reopen", ['reason' => 'The auditor adjusts depreciation'], $this->h)->assertOk();
        $this->assertSame('reopened', $this->year('2025-01-01')['status']);
        $this->assertSame('2025-12-30', $this->getJson('/api/v1/company', $this->h)->json('books_closed_until'));

        // The auditor's adjustment, then the close again: the new profit goes to retained earnings.
        $this->postJson('/api/v1/journal-entries', ['date' => '2025-12-31', 'memo' => 'Audit adjustment', 'lines' => [
            ['account_id' => $this->a['rent'], 'debit' => '500'], ['account_id' => $this->a['bank'], 'credit' => '500']]], $this->h)->assertCreated();
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/close", [], $this->h)->assertCreated()->assertJsonPath('net_profit', '6000.00');
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2025-12-31', $this->h)->json('data'))->keyBy('account_id');
        $this->assertSame('6000.00', $tb[$this->a['retained']]['credit']);
        $this->getJson('/api/v1/reports/profit-and-loss?from=2025-01-01&to=2025-12-31', $this->h)->assertJsonPath('totals.net_profit.current', '6000.00');
    }

    public function test_what_the_close_refuses_and_who_may_do_it(): void
    {
        $next = $this->year('2025-01-01');
        // The current year has not ended; an accountant closes but does not reopen.
        $current = $this->owner()->table('fiscal_years')->insertGetId(['id' => (string) Str::uuid7(), 'company_id' => $this->company, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], 'id');
        $this->postJson("/api/v1/fiscal-years/{$current}/close", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('year');

        $user = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->company, 'user_id' => $user->getKey(), 'role' => 'accountant']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'sanctum');
        $this->postJson("/api/v1/fiscal-years/{$next['id']}/close", [], $this->h)->assertCreated();
        $this->postJson("/api/v1/fiscal-years/{$next['id']}/reopen", ['reason' => 'Mistake'], $this->h)->assertForbidden();
        $this->getJson('/api/v1/fiscal-years/00000000-0000-0000-0000-000000000001/closing', $this->h)->assertNotFound();
    }
}
