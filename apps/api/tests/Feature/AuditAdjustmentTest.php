<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6: the adjustments period for the auditor. 2025 (sales 10,000.00, rent 3,500.00) is closed; the auditor's
 * adjusting entries go into it without reopening it, the closing entry follows them, and signing the audit off ends it.
 */
final class AuditAdjustmentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private string $company;

    private array $h;

    private User $ownerUser;

    /** @var array<string, string> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->ownerUser = $this->createUser('Owner', 'owner@example.test');
        $this->actingAs($this->ownerUser, 'sanctum');
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Audit Trading', 'country_code' => 'ET', 'fiscal_year_start_month' => 1,
            'conversion_date' => '2025-01-01'])->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
        foreach (['bank' => 'bank', 'sales' => 'sales', 'retained' => 'retained_earnings'] as $k => $key) {
            $this->a[$k] = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => $key])->value('id');
        }
        $this->a['rent'] = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'code' => '6100'])->value('id');
        $this->a['accrued'] = $this->postJson('/api/v1/accounts', ['code' => '2150', 'name' => 'Accrued expenses', 'type' => 'liability', 'kind' => 'accrual'], $this->h)
            ->assertCreated()->json('id');
        $post = fn (string $date, string $memo, string $dr, string $cr, string $amount) => $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => $memo, 'lines' => [
            ['account_id' => $dr, 'debit' => $amount], ['account_id' => $cr, 'credit' => $amount]]], $this->h)->assertCreated();
        $post('2025-03-10', 'Coffee sold for cash', $this->a['bank'], $this->a['sales'], '10000');
        $post('2025-06-30', 'Rent for the year', $this->a['rent'], $this->a['bank'], '3500');
        $this->postJson("/api/v1/fiscal-years/{$this->year('2025-01-01')['id']}/close", [], $this->h)->assertCreated()->assertJsonPath('net_profit', '6500.00');
    }

    private function year(string $starts): array
    {
        return collect($this->getJson('/api/v1/fiscal-years', $this->h)->assertOk()->json('data'))->firstWhere('starts_on', $starts);
    }

    private function adjust(string $yearId, array $lines, string $memo = 'Auditor AJE 1: audit fee accrued'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/fiscal-years/{$yearId}/adjustments", ['memo' => $memo, 'lines' => $lines], $this->h);
    }

    public function test_an_adjustment_goes_into_the_closed_year_and_the_close_follows_it(): void
    {
        $year = $this->year('2025-01-01');
        $this->assertSame([true, 0], [$year['can_adjust'], $year['adjustments']]);
        $before = collect($this->getJson("/api/v1/fiscal-years/{$year['id']}/adjusted-trial-balance", $this->h)->assertOk()->assertJsonPath('can.adjust', true)->json('lines'))->keyBy('account_id');
        $this->assertSame(['3500.00', '0.00', '3500.00'], [$before[$this->a['rent']]['unadjusted'], $before[$this->a['rent']]['adjustments'], $before[$this->a['rent']]['adjusted']]);
        $this->assertSame('-10000.00', $before[$this->a['sales']]['unadjusted'], 'the worksheet shows the year before its close');

        $done = $this->adjust($year['id'], [['account_id' => $this->a['rent'], 'debit' => '500', 'description' => 'Audit fee'], ['account_id' => $this->a['accrued'], 'credit' => '500']])
            ->assertCreated()->assertJsonPath('net_profit', '6000.00');
        $entry = $this->getJson("/api/v1/journal-entries/{$done->json('adjustment.id')}", $this->h)->assertOk()
            ->assertJsonPath('source_type', 'adjustment')->assertJsonPath('source_label', 'audit adjustment')->assertJsonPath('date', '2025-12-31')->assertJsonPath('can.reverse', false);
        $this->assertSame('Auditor AJE 1: audit fee accrued', $entry->json('memo'));

        // The year stays closed and locked; retained earnings take the adjustment in; one closing entry stands.
        $this->assertSame('closed', $this->year('2025-01-01')['status']);
        $this->assertSame(1, $this->year('2025-01-01')['adjustments']);
        $this->assertSame('2025-12-31', $this->getJson('/api/v1/company', $this->h)->json('books_closed_until'));
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2025-12-31', $this->h)->assertOk()->json('data'))->keyBy('account_id');
        $this->assertSame('6000.00', $tb[$this->a['retained']]['credit']);
        $this->assertArrayNotHasKey($this->a['rent'], $tb->all());
        $this->getJson('/api/v1/reports/profit-and-loss?from=2025-01-01&to=2025-12-31', $this->h)->assertOk()->assertJsonPath('totals.net_profit.current', '6000.00');
        $this->getJson('/api/v1/reports/balance-sheet?as_of=2025-12-31', $this->h)->assertOk()->assertJsonPath('equation.balanced', true);
        $this->assertSame(1, $this->owner()->table('journal_entries')->where(['company_id' => $this->company, 'source_type' => 'closing', 'source_id' => $year['id'], 'status' => 'posted'])->count());
        $after = collect($this->getJson("/api/v1/fiscal-years/{$year['id']}/adjusted-trial-balance", $this->h)->assertOk()->assertJsonCount(1, 'adjustments')->json('lines'))->keyBy('account_id');
        $this->assertSame(['3500.00', '500.00', '4000.00'], [$after[$this->a['rent']]['unadjusted'], $after[$this->a['rent']]['adjustments'], $after[$this->a['rent']]['adjusted']]);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $year['id'], 'action' => 'audit_adjustment_posted'])->count());
    }

    public function test_what_an_adjustment_may_not_do_and_who_posts_it(): void
    {
        $year = $this->year('2025-01-01');
        $lines = [['account_id' => $this->a['rent'], 'debit' => '500'], ['account_id' => $this->a['accrued'], 'credit' => '500']];
        $this->adjust($year['id'], [['account_id' => $this->a['retained'], 'debit' => '500'], ['account_id' => $this->a['accrued'], 'credit' => '500']])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->adjust($year['id'], [['account_id' => $this->a['rent'], 'debit' => '500'], ['account_id' => $this->a['accrued'], 'credit' => '400']])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->adjust($this->year('2026-01-01')['id'], $lines)->assertUnprocessable()->assertJsonValidationErrors('year');

        // An accountant prepares the books; adjusting a closed year is for an owner or admin.
        $accountant = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->company, 'user_id' => $accountant->getKey(), 'role' => 'accountant']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($accountant, 'sanctum');
        $this->adjust($year['id'], $lines)->assertForbidden();
        $this->assertFalse($this->year('2025-01-01')['can_adjust']);
        $this->getJson("/api/v1/fiscal-years/{$year['id']}/adjusted-trial-balance", $this->h)->assertOk()->assertJsonPath('can.adjust', false);
        $this->assertSame(0, $this->owner()->table('journal_entries')->where(['company_id' => $this->company, 'source_type' => 'adjustment'])->count());
    }

    public function test_signing_the_audit_off_ends_the_adjustments_and_keeps_the_year_closed(): void
    {
        $year = $this->year('2025-01-01');
        $lines = [['account_id' => $this->a['rent'], 'debit' => '500'], ['account_id' => $this->a['accrued'], 'credit' => '500']];
        $this->postJson("/api/v1/fiscal-years/{$this->year('2026-01-01')['id']}/finalise", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/finalise", [], $this->h)->assertOk()->assertJsonPath('can.adjust', false);
        $signed = $this->year('2025-01-01');
        $this->assertNotNull($signed['adjustments_closed_at']);
        $this->assertSame(['Owner', false, false], [$signed['signed_off_by'], $signed['can_adjust'], $signed['can_reopen']]);

        $this->adjust($year['id'], $lines)->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/reopen", ['reason' => 'Auditor found more'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson("/api/v1/fiscal-years/{$year['id']}/finalise", [], $this->h)->assertUnprocessable();
        $this->assertSame('closed', $this->year('2025-01-01')['status']);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $year['id'], 'action' => 'audit_signed_off'])->count());
    }
}
