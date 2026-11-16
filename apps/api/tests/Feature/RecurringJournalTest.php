<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ledger\RecurringJournals;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: recurring journals posted on a schedule, and journals that reverse themselves on a later date. */
final class RecurringJournalTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->ownerUser = $this->lastUser;
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /** @param array<string, mixed> $over */
    private function template(array $over = [], string $amount = '300'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/recurring-journals', [...['name' => 'Generator lease', 'memo' => 'Generator lease for the month', 'frequency' => 'monthly',
            'next_on' => '2026-10-31', 'lines' => [['account_id' => $this->c['rent'], 'debit' => $amount, 'description' => 'Lease'], ['account_id' => $this->c['equity'], 'credit' => $amount]]], ...$over], $this->h);
    }

    private function setLimit(?string $limit): void
    {
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'approval' => ['journal_above' => $limit]], $this->h)->assertOk();
    }

    public function test_the_next_date_keeps_its_day_and_month_ends_stay_month_ends(): void
    {
        $this->assertSame('2027-02-28', RecurringJournals::advance('2027-01-31', 'monthly'));
        $this->assertSame('2027-03-31', RecurringJournals::advance('2027-02-28', 'monthly'));
        $this->assertSame('2026-12-15', RecurringJournals::advance('2026-11-15', 'monthly'));
        $this->assertSame('2027-02-15', RecurringJournals::advance('2026-11-15', 'quarterly'));
        $this->assertSame('2029-02-28', RecurringJournals::advance('2028-02-29', 'yearly'));
        $this->assertSame('2026-09-30', RecurringJournals::advance('2026-08-30', 'monthly'));
    }

    public function test_a_template_posts_every_date_due_then_stops_at_its_end(): void
    {
        $id = $this->template(['ends_on' => '2026-12-31'])->assertCreated()->assertJsonPath('total', '300.00')->assertJsonPath('is_active', true)
            ->assertJsonPath('lines.0.code', '6100')->assertJsonPath('lines.0.description', 'Lease')->json('id');
        $this->getJson('/api/v1/recurring-journals', $this->h)->assertOk()->assertJsonPath('can.create', true)->assertJsonCount(2, 'data');   // with the fixture's

        Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00', 'UTC'));
        $done = $this->postJson('/api/v1/recurring-journals/post-due', [], $this->h)->assertOk();
        $mine = collect($done->json('data'))->firstWhere('id', $id);
        $this->assertCount(3, $mine['posted']);
        $this->assertNull($mine['error']);

        $shown = $this->getJson("/api/v1/recurring-journals/{$id}", $this->h)->assertOk()
            ->assertJsonPath('is_active', false)->assertJsonPath('posted_count', 3)->assertJsonPath('last_posted_on', '2026-12-31')->assertJsonPath('next_on', '2027-01-31');
        $this->assertSame(['2026-12-31', '2026-11-30', '2026-10-31'], collect($shown->json('entries'))->pluck('date')->all());
        $entries = $this->owner()->table('journal_entries')->where(['company_id' => $this->c['company'], 'source_type' => 'manual', 'source_id' => $id])->get();
        $this->assertCount(3, $entries);
        $this->assertSame([$this->ownerUser->getKey()], $entries->pluck('posted_by')->unique()->values()->all());
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'recurring_journal_posted'])->count());

        // Nothing more is due: running again posts nothing.
        $this->assertSame(0, $this->postJson('/api/v1/recurring-journals/post-due', [], $this->h)->assertOk()->json('posted'));
    }

    public function test_the_scheduler_posts_what_is_due_and_pauses_a_template_that_no_longer_fits(): void
    {
        $id = $this->template(['next_on' => '2026-11-30'])->assertCreated()->json('id');
        Carbon::setTestNow(Carbon::parse('2026-12-02 09:00:00', 'UTC'));
        $this->artisan('ledgerly:post-recurring')->assertSuccessful();
        // Fixture (due 1 Dec) and this one (due 30 Nov): one entry each.
        $this->assertSame(2, $this->owner()->table('journal_entries')->where(['company_id' => $this->c['company'], 'source_type' => 'manual'])
            ->whereIn('source_id', [$id, $this->c['recurring_journal']])->count());
        $this->getJson("/api/v1/recurring-journals/{$id}", $this->h)->assertOk()->assertJsonPath('next_on', '2026-12-31')->assertJsonPath('posted_count', 1)
            ->assertJsonPath('is_active', true);

        // The approval limit is lowered below its total: a recurring journal posts without approval, so it pauses with the reason.
        $this->setLimit('100');
        Carbon::setTestNow(Carbon::parse('2027-01-02 09:00:00', 'UTC'));
        $this->artisan('ledgerly:post-recurring')->assertSuccessful();
        $paused = $this->getJson("/api/v1/recurring-journals/{$id}", $this->h)->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('posted_count', 1);
        $this->assertStringContainsString('approval limit', (string) $paused->json('last_error'));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'recurring_journal_paused'])->count());

        // Resumed only once it fits again, and from a later date if asked; nothing in between is posted.
        $this->postJson("/api/v1/recurring-journals/{$id}/resume", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->setLimit(null);
        $this->postJson("/api/v1/recurring-journals/{$id}/resume", ['next_on' => '2027-01-31'], $this->h)->assertOk()
            ->assertJsonPath('is_active', true)->assertJsonPath('next_on', '2027-01-31')->assertJsonPath('last_error', null);
        $this->postJson("/api/v1/recurring-journals/{$id}/resume", [], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/recurring-journals/{$id}/pause", [], $this->h)->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_a_template_is_checked_like_a_manual_journal_and_stays_within_the_approval_limit(): void
    {
        $receivable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $this->template(['lines' => [['account_id' => $receivable, 'debit' => '10'], ['account_id' => $this->c['equity'], 'credit' => '10']]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->template(['lines' => [['account_id' => $this->c['rent'], 'debit' => '10'], ['account_id' => $this->c['equity'], 'credit' => '9']]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->template(['frequency' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('frequency');
        $this->template(['ends_on' => '2026-10-30'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->setLimit('1000');
        $this->template([], '1500')->assertUnprocessable()->assertJsonValidationErrors('lines');
        $id = $this->template([], '900')->assertCreated()->json('id');

        // Edited by someone else, it posts as them from then on.
        $accountant = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $accountant->getKey(), 'role' => 'accountant']);
        $this->as($accountant)->putJson("/api/v1/recurring-journals/{$id}", ['name' => 'Generator lease', 'memo' => 'Generator lease, new rate', 'frequency' => 'quarterly',
            'next_on' => '2026-12-31', 'lines' => [['account_id' => $this->c['rent'], 'debit' => '950'], ['account_id' => $this->c['equity'], 'credit' => '950']]], $this->h)->assertOk()
            ->assertJsonPath('posts_as', 'Accountant')->assertJsonPath('frequency', 'quarterly')->assertJsonPath('total', '950.00');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'recurring_journal_updated'])->count());
    }

    public function test_a_journal_set_to_reverse_posts_its_reversal_on_that_day(): void
    {
        $journal = fn (array $over = [], string $amount = '500') => $this->postJson('/api/v1/journal-entries', [...['date' => '2026-11-10', 'memo' => 'Accrued audit fee',
            'lines' => [['account_id' => $this->c['rent'], 'debit' => $amount], ['account_id' => $this->c['equity'], 'credit' => $amount]]], ...$over], $this->h);
        $journal(['reverse_on' => '2026-11-10'])->assertUnprocessable()->assertJsonValidationErrors('reverse_on');
        $journal(['reverse_on' => '2027-11-11'])->assertUnprocessable()->assertJsonValidationErrors('reverse_on');

        $entry = $journal(['reverse_on' => '2026-12-01'])->assertCreated()->assertJsonPath('status', 'reversed')->assertJsonPath('reversed_by.date', '2026-12-01');
        $this->assertSame('Set to reverse when it was entered', $this->owner()->table('audit_log')->where(['subject_id' => $entry->json('id'), 'action' => 'journal_reversed'])->value('reason'));

        // Above the approval limit, the request keeps the date and the approval posts both.
        $this->setLimit('1000');
        $asked = $journal(['reverse_on' => '2026-12-01'], '1500')->assertStatus(202)->assertJsonPath('request.reverse_on', '2026-12-01');
        $accountant = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $accountant->getKey(), 'role' => 'accountant']);
        $this->as($accountant)->postJson("/api/v1/journal-requests/{$asked->json('request.id')}/approve", [], $this->h)->assertCreated()
            ->assertJsonPath('status', 'reversed')->assertJsonPath('reversed_by.date', '2026-12-01')->assertJsonPath('total', '1500.00');
    }
}
