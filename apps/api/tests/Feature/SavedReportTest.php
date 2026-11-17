<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reports\SavedReports;
use App\Models\User;
use App\Notifications\SavedReportReady;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 10: saved reports with rolling or custom periods, private to each person, and sent weekly or monthly by email. */
final class SavedReportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->me = $this->lastUser;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);
        $this->app['auth']->forgetGuards();

        return $user;
    }

    public function test_rolling_periods_and_schedules_fall_on_the_right_days(): void
    {
        $saved = app(SavedReports::class);
        $on = fn (string $period) => array_values($saved->period($this->c['company'], $period, null, null, '2026-11-17'));
        $this->assertSame(['2026-11-01', '2026-11-17'], $on('this_month'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $on('last_month'));
        $this->assertSame(['2026-10-01', '2026-11-17'], $on('this_quarter'));
        $this->assertSame(['2026-07-01', '2026-09-30'], $on('last_quarter'));
        // The fiscal year to date starts on the company's own fiscal year (read in its context, through the API).
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'UTC'));
        $fiscalStart = (string) $this->owner()->table('fiscal_years')->where('company_id', $this->c['company'])->where('starts_on', '<=', '2026-11-17')
            ->where('ends_on', '>=', '2026-11-17')->value('starts_on');
        $this->postJson('/api/v1/saved-reports', ['name' => 'YTD', 'report' => 'profit-and-loss', 'period' => 'year_to_date'], $this->h)->assertCreated()
            ->assertJsonPath('covers', ['from' => $fiscalStart, 'to' => '2026-11-17']);
        $this->assertSame(['2026-12-01', '2026-12-31'], array_values($saved->period($this->c['company'], 'last_month', null, null, '2027-01-05')), 'across the year end');
        $this->assertSame('2026-11-23', $saved->nextSend('weekly', '2026-11-17'));
        $this->assertSame('2026-11-30', $saved->nextSend('weekly', '2026-11-23'), 'a Monday sends the Monday after');
        $this->assertSame('2026-12-01', $saved->nextSend('monthly', '2026-11-17'));
        $this->assertNull($saved->nextSend('none', '2026-11-17'));
    }

    public function test_a_report_is_saved_with_its_period_and_options_and_only_its_owner_sees_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'UTC'));
        $bs = $this->postJson('/api/v1/saved-reports', ['name' => ' Year-end balance sheet ', 'report' => 'balance-sheet', 'period' => 'custom', 'from' => '2026-01-01',
            'to' => '2027-07-07'], $this->h)->assertCreated()->assertJsonPath('name', 'Year-end balance sheet')->assertJsonPath('from_date', null)
            ->assertJsonPath('covers', ['as_of' => '2027-07-07'])->assertJsonPath('schedule', 'none')->assertJsonPath('next_send_on', null);
        $sales = $this->postJson('/api/v1/saved-reports', ['name' => 'Sales by salesperson', 'report' => 'sales', 'period' => 'this_month', 'schedule' => 'weekly',
            'options' => ['group' => 'salesperson', 'kind' => 'project']], $this->h)->assertCreated()
            ->assertJsonPath('options', ['group' => 'salesperson'])->assertJsonPath('covers', ['from' => '2026-11-01', 'to' => '2026-11-17'])
            ->assertJsonPath('next_send_on', '2026-11-23');
        $this->assertSame(['Monthly P&L', 'Sales by salesperson', 'Year-end balance sheet'], collect($this->getJson('/api/v1/saved-reports', $this->h)->assertOk()->json('data'))->pluck('name')->all());

        $this->putJson("/api/v1/saved-reports/{$sales->json('id')}", ['name' => 'Sales by item', 'report' => 'sales', 'period' => 'last_month', 'options' => ['group' => 'item']], $this->h)
            ->assertOk()->assertJsonPath('options', ['group' => 'item'])->assertJsonPath('schedule', 'none')->assertJsonPath('next_send_on', null);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $sales->json('id'), 'action' => 'saved_report_changed'])->count());

        // Rules: a general ledger names one of the company's accounts; options are the report's own values; a custom period has its days in order.
        $body = fn (array $more) => ['name' => 'X', 'period' => 'last_month', ...$more];
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'general-ledger']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('options.account_id');
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'general-ledger', 'options' => ['account_id' => $this->c['rent']]]), $this->h)->assertCreated();
        $other = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($this->me, 'sanctum');
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'general-ledger', 'options' => ['account_id' => $other['rent']]]), $this->h)->assertUnprocessable()
            ->assertJsonValidationErrors('options.account_id');
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'stock-ageing', 'options' => ['warehouse_id' => $other['store']]]), $this->h)->assertUnprocessable();
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'sales', 'options' => ['group' => 'DROP TABLE']]), $this->h)->assertUnprocessable();
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'payroll']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'cash-flow', 'period' => 'custom', 'to' => '2026-10-31']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson('/api/v1/saved-reports', $body(['report' => 'cash-flow', 'period' => 'custom', 'from' => '2026-11-30', 'to' => '2026-10-31']), $this->h)->assertUnprocessable();

        // Another member of the company, and another company, see none of it.
        $this->actingAs($this->member('accountant'), 'sanctum');
        $this->getJson('/api/v1/saved-reports', $this->h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/saved-reports/{$bs->json('id')}", $this->h)->assertNotFound();
        $this->putJson("/api/v1/saved-reports/{$bs->json('id')}", $body(['report' => 'cash-flow']), $this->h)->assertNotFound();
        $this->deleteJson("/api/v1/saved-reports/{$bs->json('id')}", [], $this->h)->assertNotFound();
        $this->actingAs($this->lastUserOf($other), 'sanctum');
        $this->getJson("/api/v1/saved-reports/{$bs->json('id')}", ['X-Company-Id' => $other['company']])->assertNotFound();

        $this->actingAs($this->me, 'sanctum');
        $this->deleteJson("/api/v1/saved-reports/{$bs->json('id')}", [], $this->h)->assertOk();
        $this->getJson("/api/v1/saved-reports/{$bs->json('id')}", $this->h)->assertNotFound();
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $bs->json('id'), 'action' => 'saved_report_removed'])->count());
    }

    public function test_the_scheduler_emails_a_link_when_it_is_due_and_moves_the_schedule_on(): void
    {
        Notification::fake();
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'sanctum');
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'UTC'));
        $weekly = $this->postJson('/api/v1/saved-reports', ['name' => 'Weekly AR', 'report' => 'ar-ageing', 'period' => 'this_month', 'schedule' => 'weekly'], $this->h)
            ->assertCreated()->assertJsonPath('next_send_on', '2026-11-23')->json('id');

        // Before its day nothing goes; on the Monday it goes once, to the person who saved it, and then waits a week.
        Carbon::setTestNow(Carbon::parse('2026-11-21 09:00:00', 'UTC'));
        $this->artisan('ledgerly:send-saved-reports')->assertSuccessful();
        Notification::assertNothingSent();
        Carbon::setTestNow(Carbon::parse('2026-11-23 09:00:00', 'UTC'));
        $this->artisan('ledgerly:send-saved-reports')->assertSuccessful();
        Notification::assertSentTo($accountant, SavedReportReady::class, fn (SavedReportReady $n) => $n->savedReportId === $weekly && $n->covers === 'at 2026-11-23'
            && str_ends_with(SavedReportReady::url($n->savedReportId), "/reports/saved/{$weekly}"));
        Notification::assertNotSentTo($this->me, SavedReportReady::class);
        $this->assertSame('2026-11-30', (string) $this->owner()->table('saved_reports')->where('id', $weekly)->value('next_send_on'));
        $this->artisan('ledgerly:send-saved-reports')->assertSuccessful();
        Notification::assertSentToTimes($accountant, SavedReportReady::class, 1);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $weekly, 'action' => 'saved_report_sent'])->count());

        // The owner's monthly profit and loss (the fixtures) goes on the 1st, covering last month.
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
        $this->artisan('ledgerly:send-saved-reports')->assertSuccessful();
        Notification::assertSentTo($this->me, SavedReportReady::class, fn (SavedReportReady $n) => $n->savedReportId === $this->c['saved_report']
            && $n->covers === '2026-11-01 to 2026-11-30' && $n->company === 'Abyssinia Coffee');

        // The weekly one was due again on 30 Nov, so it went with the monthly one: twice in all. Someone who left is sent nothing more.
        Notification::assertSentToTimes($accountant, SavedReportReady::class, 2);
        $this->owner()->table('memberships')->where(['company_id' => $this->c['company'], 'user_id' => $accountant->getKey()])->update(['status' => 'deactivated']);
        Carbon::setTestNow(Carbon::parse('2026-12-07 09:00:00', 'UTC'));
        $this->artisan('ledgerly:send-saved-reports')->assertSuccessful();
        Notification::assertSentToTimes($accountant, SavedReportReady::class, 2);
    }

    /** The owner of a company set up by companyWithRecords. @param array<string, string> $ids */
    private function lastUserOf(array $ids): User
    {
        return User::query()->findOrFail($ids['user']);
    }
}
