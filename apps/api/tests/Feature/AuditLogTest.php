<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 10/11: the audit log as a report, filtered and paged, and the check of its hash chain. */
final class AuditLogTest extends TestCase
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

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);
        $this->app['auth']->forgetGuards();

        return $user;
    }

    public function test_the_log_shows_every_change_with_who_when_and_what(): void
    {
        $all = $this->getJson('/api/v1/audit-log', $this->h)->assertOk();
        $ids = collect($all->json('data'))->pluck('id')->all();
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all(), 'newest first');
        $this->assertContains('asset_registered', $all->json('actions'));
        $this->assertSame($this->lastUser->name, $all->json('data.0.user.name'));

        $asset = $this->getJson('/api/v1/audit-log?action=asset_registered', $this->h)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_type', 'fixed_asset')->assertJsonPath('data.0.subject_id', $this->c['fixed_asset'])
            ->assertJsonPath('data.0.after.code', 'IT-001')->assertJsonPath('data.0.before', null);
        $this->getJson("/api/v1/audit-log?subject_id={$this->c['fixed_asset']}", $this->h)->assertOk()->assertJsonPath('data.0.id', $asset->json('data.0.id'));
        $this->getJson('/api/v1/audit-log?from=2099-01-01', $this->h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audit-log?action=DROP%20TABLE', $this->h)->assertUnprocessable()->assertJsonValidationErrors('action');

        // Paged by id: the next page starts below the last row shown.
        $first = $this->getJson('/api/v1/audit-log', $this->h)->assertOk();
        if ($first->json('more')) {
            $next = $this->getJson('/api/v1/audit-log?before_id=' . $first->json('next_before_id'), $this->h)->assertOk();
            $this->assertLessThan($first->json('next_before_id'), $next->json('data.0.id'));
        } else {
            $this->assertNull($first->json('next_before_id'));
        }
    }

    public function test_the_chain_is_checked_and_who_may_read_the_log(): void
    {
        $this->getJson('/api/v1/audit-log/verify', $this->h)->assertOk()->assertJsonPath('intact', true)->assertJsonPath('broken_at', null);
        $this->assertGreaterThan(10, $this->getJson('/api/v1/audit-log/verify', $this->h)->json('rows'));

        $this->actingAs($this->member('accountant'), 'sanctum');
        $this->getJson('/api/v1/audit-log', $this->h)->assertOk();
        $this->actingAs($this->member('bookkeeper'), 'sanctum');
        $this->getJson('/api/v1/audit-log', $this->h)->assertForbidden();
        $this->getJson('/api/v1/audit-log/verify', $this->h)->assertForbidden();
    }
}
