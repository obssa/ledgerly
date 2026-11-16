<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6, accountant tools: the review queue of unusual entries, and marking them reviewed. */
final class ReviewQueueTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $accrued;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->accrued = $this->postJson('/api/v1/accounts', ['code' => '2150', 'name' => 'Accrued expenses', 'type' => 'liability', 'kind' => 'accrual'], $this->h)
            ->assertCreated()->json('id');
        // The queue judges "at night" by the company's clock: put it at midday, so only the test that wants night has it.
        $this->setTimezone($this->h, self::zoneAt(12));
    }

    /** A time zone where the hour is now $hour. */
    private static function zoneAt(int $hour): string
    {
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            if ((int) now($zone)->format('G') === $hour) {
                return $zone;
            }
        }
        throw new \LogicException("No zone at {$hour}:00");
    }

    private function setTimezone(array $h, string $zone): void
    {
        $version = $this->getJson('/api/v1/company', $h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'timezone' => $zone], $h)->assertOk();
    }

    private function journal(string $date, string $amount, string $credit, string $memo): string
    {
        return $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => $memo, 'lines' => [
            ['account_id' => $this->c['rent'], 'debit' => $amount], ['account_id' => $credit, 'credit' => $amount]]], $this->h)->assertCreated()->json('id');
    }

    /** @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function queue(string $query = ''): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/v1/journal-entries/review?from=2026-07-01&to=2026-11-30{$query}", $this->h)->assertOk()->json('data'))->keyBy('id');
    }

    public function test_the_queue_shows_unusual_entries_and_why(): void
    {
        foreach (['100', '120', '90', '110', '105'] as $i => $amount) {
            $this->journal('2026-11-1' . $i, $amount, $this->accrued, "Small accrual {$i}");
        }
        $round = $this->journal('2026-11-15', '20000', $this->accrued, 'Bonus provision');
        $late = $this->journal('2026-09-30', '250', $this->accrued, 'September accrual found late');
        $cash = $this->journal('2026-11-12', '75', $this->c['bank'], 'Petty expense through the bank');

        $q = $this->queue();
        $this->assertSame(['round_amount', 'large'], $q[$round]['flags']);
        $this->assertSame('20000.00', $q[$round]['total']);
        $this->assertContains('backdated', $q[$late]['flags']);
        $this->assertSame(['manual_cash'], $q[$cash]['flags']);
        // The fixture's "Opening cash" journal moved cash by a journal (the company has no conversion date to judge equity by).
        $this->assertSame(['manual_cash'], $q[$this->c['entry']]['flags']);
        $this->assertCount(0, $q->filter(fn ($e) => str_starts_with((string) $e['memo'], 'Small accrual')), 'ordinary journals are not in the queue');
        $this->assertSame(0, $q->filter(fn ($e) => $e['source_type'] === 'invoice')->count());
        $this->getJson('/api/v1/journal-entries/review', $this->h)->assertOk()->assertJsonPath('can.review', true)
            ->assertJsonStructure(['from', 'to', 'data', 'counts' => ['manual_cash', 'round_amount', 'large', 'backdated', 'out_of_hours', 'opening_equity'], 'flags', 'truncated']);
    }

    public function test_opening_balance_equity_after_the_conversion_is_flagged(): void
    {
        $company = $this->postJson('/api/v1/companies', ['legal_name' => 'Converted Trading', 'country_code' => 'ET', 'fiscal_year_start_month' => 1,
            'conversion_date' => '2025-01-01'])->assertCreated()->json('id');
        $h = ['X-Company-Id' => $company];
        $this->setTimezone($h, self::zoneAt(12));
        $key = fn (string $k) => $this->owner()->table('accounts')->where(['company_id' => $company, 'system_key' => $k])->value('id');
        $entry = $this->postJson('/api/v1/journal-entries', ['date' => '2025-03-10', 'memo' => 'Owner put money in', 'lines' => [
            ['account_id' => $key('bank'), 'debit' => '500'], ['account_id' => $key('opening_balance_equity'), 'credit' => '500']]], $h)->assertCreated()->json('id');
        $q = collect($this->getJson('/api/v1/journal-entries/review?from=2025-01-01&to=2025-12-31', $h)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['manual_cash', 'backdated', 'opening_equity'], $q[$entry]['flags']);   // posted long after March 2025
    }

    public function test_an_entry_posted_at_night_is_flagged_by_the_company_clock(): void
    {
        $entry = $this->journal('2026-11-10', '120', $this->accrued, 'Ordinary accrual');
        $this->assertArrayNotHasKey($entry, $this->queue()->all(), 'posted at midday, company time');
        $this->setTimezone($this->h, self::zoneAt(23));
        $this->assertSame(['out_of_hours'], $this->queue()[$entry]['flags']);
    }

    public function test_a_reviewed_entry_leaves_the_queue_with_its_note_kept(): void
    {
        $round = $this->journal('2026-11-15', '20000', $this->accrued, 'Bonus provision');
        $this->postJson("/api/v1/journal-entries/{$round}/review", ['note' => 'Agreed to the board minute of 14 Nov'], $this->h)->assertCreated()
            ->assertJsonPath('flags', ['round_amount']);
        $this->postJson("/api/v1/journal-entries/{$round}/review", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('entry');

        $this->assertArrayNotHasKey($round, $this->queue()->all());
        $reviewed = $this->queue('&reviewed=1')[$round];
        $this->assertSame([$this->lastUser->name, 'Agreed to the board minute of 14 Nov'], [$reviewed['review']['by'], $reviewed['review']['note']]);
        $stored = $this->owner()->table('journal_entry_reviews')->where('journal_entry_id', $round)->first();
        $this->assertSame('{round_amount}', $stored->flags);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $round, 'action' => 'journal_reviewed'])->count());
    }

    public function test_who_reviews(): void
    {
        $round = $this->journal('2026-11-15', '20000', $this->accrued, 'Bonus provision');
        $member = function (string $role): User {
            $user = $this->createUser(ucfirst($role), "{$role}@example.test");
            $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);
            $this->app['auth']->forgetGuards();

            return $user;
        };
        $this->actingAs($member('bookkeeper'), 'sanctum');
        $this->getJson('/api/v1/journal-entries/review', $this->h)->assertOk()->assertJsonPath('can.review', false);
        $this->postJson("/api/v1/journal-entries/{$round}/review", [], $this->h)->assertForbidden();
        $this->actingAs($member('approver'), 'sanctum');
        $this->postJson("/api/v1/journal-entries/{$round}/review", ['note' => 'Seen'], $this->h)->assertCreated();
    }
}
