<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 9a: hours logged against a project, and billed on a draft invoice for its customer. */
final class TimeEntryTest extends TestCase
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

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    private function log(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/time-entries', [...['project_id' => $this->c['project'], 'work_date' => '2026-11-10', 'hours' => '2.5', 'billable' => true], ...$over], $this->h);
    }

    public function test_billable_hours_are_billed_on_a_draft_invoice_for_the_customer_and_then_fixed(): void
    {
        $p = $this->c['project'];
        $this->putJson("/api/v1/projects/{$p}", ['name' => 'Fit-out', 'status' => 'active', 'billing' => 'time_and_materials', 'hourly_rate' => '500', 'customer_id' => $this->c['contact']], $this->h)
            ->assertOk()->assertJsonPath('hourly_rate', '500.00');
        $this->log(['hours' => '2.5', 'note' => 'Design meeting'])->assertCreated()->assertJsonPath('rate', null);   // at the project's rate
        $this->log(['hours' => '4', 'billable' => false, 'note' => 'Internal review'])->assertCreated();
        // Fixture: 3 hours at 400 (1,200). Here: 2.5 at the project's 500 (1,250). Not billable: 4 hours.
        $this->getJson("/api/v1/projects/{$p}", $this->h)->assertOk()
            ->assertJsonPath('time.logged_hours', '9.50')->assertJsonPath('time.unbilled_hours', '5.50')->assertJsonPath('time.unbilled_value', '2450.00');

        $income = $this->c['sales'];
        $billed = $this->postJson("/api/v1/projects/{$p}/bill-time", ['issue_date' => '2026-11-15', 'income_account_id' => $income], $this->h)->assertCreated()
            ->assertJsonPath('entries', 2)->assertJsonPath('invoice.total', '2450.00');
        $invoice = $this->getJson("/api/v1/invoices/{$billed->json('invoice.id')}", $this->h)->assertOk()->assertJsonPath('status', 'draft')
            ->assertJsonPath('contact_id', $this->c['contact']);
        $this->assertSame([['3.00', '400.00', $p], ['2.50', '500.00', $p]], collect($invoice->json('lines'))->map(fn ($l) => [$l['quantity'], $l['unit_price'], $l['project_id']])->all());
        $this->assertStringContainsString('Design meeting', (string) collect($invoice->json('lines'))->last()['description']);
        $this->getJson("/api/v1/projects/{$p}", $this->h)->assertOk()->assertJsonPath('time.unbilled_hours', '0.00');
        $this->postJson("/api/v1/projects/{$p}/bill-time", ['issue_date' => '2026-11-15', 'income_account_id' => $income], $this->h)->assertUnprocessable()->assertJsonValidationErrors('entry_ids');

        // Billed hours are fixed.
        $this->putJson("/api/v1/time-entries/{$this->c['time_entry']}", ['project_id' => $p, 'work_date' => '2026-11-09', 'hours' => '5', 'billable' => true], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->deleteJson("/api/v1/time-entries/{$this->c['time_entry']}", [], $this->h)->assertUnprocessable();
        $listed = collect($this->getJson("/api/v1/time-entries?project_id={$p}", $this->h)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($billed->json('invoice.id'), $listed[$this->c['time_entry']]['invoice']['id']);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $p, 'action' => 'time_billed'])->count());
    }

    public function test_people_log_their_own_hours_and_whoever_manages_projects_sees_everyones(): void
    {
        $bookkeeper = $this->member('bookkeeper');
        $this->as($bookkeeper)->log(['note' => 'Stock count on site'])->assertCreated()->assertJsonPath('user.id', $bookkeeper->getKey());
        $this->log(['user_id' => $this->ownerUser->getKey()])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertCount(1, $this->getJson('/api/v1/time-entries', $this->h)->assertOk()->assertJsonPath('can.manage', false)->json('data'), 'only their own');
        $this->putJson("/api/v1/time-entries/{$this->c['time_entry']}", ['project_id' => $this->c['project'], 'work_date' => '2026-11-09', 'hours' => '1', 'billable' => true], $this->h)
            ->assertNotFound();   // someone else's hours are not theirs to see

        $this->as($this->ownerUser);
        $this->assertCount(2, $this->getJson('/api/v1/time-entries', $this->h)->assertOk()->assertJsonPath('can.manage', true)->json('data'));
        $this->log(['user_id' => $bookkeeper->getKey(), 'hours' => '1'])->assertCreated()->assertJsonPath('user.name', 'Bookkeeper');
        $this->log(['user_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->log(['hours' => '25'])->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->log(['work_date' => '2099-01-01'])->assertUnprocessable()->assertJsonValidationErrors('work_date');
        $unbilled = $this->log(['hours' => '1'])->assertCreated()->json('id');
        $this->deleteJson("/api/v1/time-entries/{$unbilled}", [], $this->h)->assertNoContent();
    }

    public function test_hours_without_a_rate_are_not_billed_and_a_project_without_a_customer_is_not_billed(): void
    {
        $p = $this->c['project'];
        $this->log(['hours' => '2'])->assertCreated();   // no rate, and the project has none
        $this->postJson("/api/v1/projects/{$p}/bill-time", ['issue_date' => '2026-11-15', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('entry_ids');
        $internal = $this->postJson('/api/v1/projects', ['code' => 'INT', 'name' => 'Internal', 'status' => 'active', 'billing' => 'non_billable'], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/projects/{$internal}/bill-time", ['issue_date' => '2026-11-15', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->assertSame(0, $this->owner()->table('documents')->where(['company_id' => $this->c['company'], 'notes' => "Time on P-1 Abyssinia Coffee fit-out"])->count());
    }
}
