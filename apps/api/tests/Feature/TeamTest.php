<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvitationToJoin;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 11: inviting people by email, accepting by link, roles, deactivating and reactivating, by owners and admins only. */
final class TeamTest extends TestCase
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
        Notification::fake();
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->me = $this->lastUser;
    }

    /** The token in the last invitation sent to someone. */
    private function tokenFor(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, InvitationToJoin::class, function (InvitationToJoin $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return (string) $token;
    }

    /** As someone not signed in (the link is opened from an email). */
    private function signedOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
    }

    public function test_someone_new_is_invited_chooses_a_password_and_joins(): void
    {
        $invited = $this->postJson('/api/v1/members/invitations', ['email' => 'Selam@Example.test', 'name' => 'Selam Bekele', 'role' => 'accountant'], $this->h)
            ->assertCreated()->assertJsonPath('status', 'invited')->assertJsonPath('user.email', 'selam@example.test')->assertJsonPath('role', 'accountant');
        $selam = User::query()->where('email', 'selam@example.test')->sole();
        $this->assertNull($selam->email_verified_at);
        $token = $this->tokenFor($selam);
        $this->postJson('/api/v1/auth/login', ['email' => 'selam@example.test', 'password' => 'anything at all 123'])->assertUnprocessable();

        $this->signedOut();
        $this->getJson("/api/v1/auth/invitation?token={$token}")->assertOk()->assertJsonPath('company_name', 'Abyssinia Coffee')
            ->assertJsonPath('role', 'accountant')->assertJsonPath('new_account', true)->assertJsonPath('email', 'selam@example.test');
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $token, 'name' => 'Selam B.', 'password' => 'Coffee-Ledger-2026!'])->assertOk()
            ->assertJsonPath('accepted', true)->assertJsonPath('company', 'Abyssinia Coffee');
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $token, 'password' => 'Coffee-Ledger-2026!'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->getJson("/api/v1/auth/invitation?token={$token}")->assertNotFound();

        $this->assertSame('active', $this->owner()->table('memberships')->where('id', $invited->json('id'))->value('status'));
        $this->assertNotNull($selam->refresh()->email_verified_at);
        $this->assertSame('Selam B.', $selam->name);
        $this->postJson('/api/v1/auth/login', ['email' => 'selam@example.test', 'password' => 'Coffee-Ledger-2026!'])->assertOk();
        $this->assertSame(['member_invited', 'invitation_accepted'], $this->owner()->table('audit_log')->where('subject_id', $invited->json('id'))->orderBy('id')->pluck('action')->all());
    }

    public function test_someone_with_an_account_joins_by_the_link_and_keeps_their_password(): void
    {
        $other = $this->companyWithRecords('abebe@example.test', 'Harar Traders');
        $abebe = $this->lastUser;
        $this->actingAs($this->me, 'sanctum');
        $m = $this->postJson('/api/v1/members/invitations', ['email' => 'abebe@example.test', 'role' => 'viewer'], $this->h)->assertCreated()->json('id');
        Notification::assertSentTo($abebe, InvitationToJoin::class, fn (InvitationToJoin $n) => $n->newAccount === false && $n->company === 'Abyssinia Coffee');
        $token = $this->tokenFor($abebe);
        $before = $abebe->password;

        $this->signedOut();
        $this->getJson("/api/v1/auth/invitation?token={$token}")->assertOk()->assertJsonPath('new_account', false);
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $token, 'password' => 'Ignored-Password-1!'])->assertOk();
        $this->assertSame($before, $abebe->refresh()->password, 'an existing account keeps its password');
        $this->assertSame('active', $this->owner()->table('memberships')->where('id', $m)->value('status'));
        // Their own company is untouched; the new one is in their list of companies.
        $this->actingAs($abebe, 'sanctum');
        $this->getJson('/api/v1/company', ['X-Company-Id' => $other['company']])->assertOk();
        $this->getJson('/api/v1/company', $this->h)->assertOk();
        $this->getJson('/api/v1/members', $this->h)->assertForbidden();   // a viewer does not manage people
    }

    public function test_roles_are_changed_and_people_deactivated_within_the_owner_rules(): void
    {
        $admin = $this->postJson('/api/v1/members/invitations', ['email' => 'admin@example.test', 'name' => 'Meron', 'role' => 'admin'], $this->h)->assertCreated()->json('id');
        $this->signedOut();
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $this->tokenFor(User::query()->where('email', 'admin@example.test')->sole()), 'password' => 'Admin-Ledger-2026!'])->assertOk();
        $meron = User::query()->where('email', 'admin@example.test')->sole();
        // An admin works in the company only with an authenticator app (SECURITY.md S2): Meron sets one up.
        $meron->forceFill(['mfa_secret' => \App\Domain\Identity\Totp::generateSecret(), 'mfa_confirmed_at' => now()])->save();
        $meron = $meron->refresh();
        $this->actingAs($this->me, 'sanctum');
        $list = $this->getJson('/api/v1/members', $this->h)->assertOk();
        $this->assertContains('owner', $list->json('roles'));
        $rows = collect($list->json('data'));
        $this->assertSame([true, false], [$rows->firstWhere('user.email', 'owner@example.test')['me'], $rows->firstWhere('user.email', 'admin@example.test')['me']]);

        // An admin manages people but cannot make or touch an owner, nor invite one.
        $this->actingAs($meron, 'sanctum');
        $this->assertNotContains('owner', $this->getJson('/api/v1/members', $this->h)->json('roles'));
        $this->postJson('/api/v1/members/invitations', ['email' => 'x@example.test', 'name' => 'X', 'role' => 'owner'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->putJson("/api/v1/members/{$this->c['membership']}", ['role' => 'viewer'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('membership');
        $this->postJson("/api/v1/members/{$this->c['membership']}/deactivate", [], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/members/{$admin}/deactivate", [], $this->h)->assertUnprocessable()->assertJsonPath('errors.membership.0', 'You cannot deactivate yourself.');
        $bk = $this->postJson('/api/v1/members/invitations', ['email' => 'bk@example.test', 'name' => 'Dawit', 'role' => 'bookkeeper'], $this->h)->assertCreated()->json('id');
        $this->putJson("/api/v1/members/{$bk}", ['role' => 'sales'], $this->h)->assertOk()->assertJsonPath('role', 'sales');
        $this->postJson("/api/v1/members/{$bk}/resend", [], $this->h)->assertOk();
        Notification::assertSentToTimes(User::query()->where('email', 'bk@example.test')->sole(), InvitationToJoin::class, 2);
        $this->postJson("/api/v1/members/{$bk}/deactivate", [], $this->h)->assertOk()->assertJsonPath('status', 'deactivated');
        $this->postJson('/api/v1/members/invitations', ['email' => 'bk@example.test', 'role' => 'sales'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("/api/v1/members/{$bk}/reactivate", [], $this->h)->assertOk()->assertJsonPath('status', 'active');

        // The owner makes the admin an owner too, then steps down to admin: the company keeps an owner throughout.
        $this->actingAs($this->me, 'sanctum');
        $this->putJson("/api/v1/members/{$admin}", ['role' => 'owner'], $this->h)->assertOk();
        $this->putJson("/api/v1/members/{$this->c['membership']}", ['role' => 'admin'], $this->h)->assertOk();
        $this->actingAs($meron, 'sanctum');
        $this->putJson("/api/v1/members/{$admin}", ['role' => 'admin'], $this->h)->assertUnprocessable()->assertJsonPath('errors.membership.0', 'A company always keeps at least one active owner.');
        $this->assertSame(['member_invited', 'invitation_accepted', 'member_role_changed'], $this->owner()->table('audit_log')->where('subject_id', $admin)->orderBy('id')->pluck('action')->all());
    }

    public function test_another_companys_people_are_out_of_reach_and_a_deactivated_person_is_out(): void
    {
        $other = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($this->me, 'sanctum');
        $this->putJson("/api/v1/members/{$other['membership']}", ['role' => 'viewer'], $this->h)->assertNotFound();
        $this->postJson("/api/v1/members/{$other['membership']}/deactivate", [], $this->h)->assertNotFound();
        $this->assertNotContains('other@example.test', collect($this->getJson('/api/v1/members', $this->h)->json('data'))->pluck('user.email'));
        $this->postJson("/api/v1/members/" . Str::uuid() . "/resend", [], $this->h)->assertNotFound();

        $m = $this->postJson('/api/v1/members/invitations', ['email' => 'temp@example.test', 'name' => 'Temp', 'role' => 'bookkeeper'], $this->h)->assertCreated()->json('id');
        $temp = User::query()->where('email', 'temp@example.test')->sole();
        $token = $this->tokenFor($temp);
        $this->postJson("/api/v1/members/{$m}/deactivate", [], $this->h)->assertOk();
        $this->signedOut();
        $this->getJson("/api/v1/auth/invitation?token={$token}")->assertNotFound();   // a withdrawn invitation's link is dead
        $this->getJson('/api/v1/auth/invitation?token=' . str_repeat('a', 64))->assertNotFound();
        $this->getJson('/api/v1/auth/invitation?token=short')->assertUnprocessable();
    }

    public function test_the_team_list_says_who_may_correct_posted_documents(): void
    {
        $m = $this->postJson('/api/v1/members/invitations', ['email' => 'selam@example.test', 'name' => 'Selam', 'role' => 'viewer'], $this->h)->assertCreated()->json('id');
        $canCorrect = fn () => collect($this->getJson('/api/v1/members', $this->h)->assertOk()->json('data'))->firstWhere('id', $m)['can_correct'];

        $this->assertFalse($canCorrect());
        $this->assertTrue(collect($this->getJson('/api/v1/members', $this->h)->json('data'))->firstWhere('me', true)['can_correct']);
        foreach ([['accountant', [], true], ['bookkeeper', [], true], ['sales', [], false], ['custom', ['invoices.view'], false], ['custom', ['invoices.view', 'transactions.edit'], true]] as [$role, $permissions, $expected]) {
            $this->putJson("/api/v1/members/{$m}", ['role' => $role, 'permissions' => $permissions], $this->h)->assertOk();
            $this->assertSame($expected, $canCorrect(), $role . ' ' . implode(',', $permissions));
        }
    }

    public function test_a_custom_role_works_with_exactly_the_permissions_chosen_for_it(): void
    {
        $grantable = collect($this->getJson('/api/v1/members', $this->h)->assertOk()->json('grantable'))->pluck('permission');
        $this->assertContains('invoices.view', $grantable);
        foreach (['users.manage', 'company.export', 'company.manage', 'periods.reopen', '*'] as $kept) {
            $this->assertNotContains($kept, $grantable, "{$kept} is for owners and admins only");
        }

        $invite = fn (array $permissions) => $this->postJson('/api/v1/members/invitations',
            ['email' => 'tigist@example.test', 'name' => 'Tigist', 'role' => 'custom', 'permissions' => $permissions], $this->h);
        $invite([])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $invite(['*'])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $invite(['users.manage'])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $m = $invite(['reports.view', 'invoices.view'])->assertCreated()->assertJsonPath('role', 'custom')
            ->assertJsonPath('permissions', ['company.view', 'invoices.view', 'reports.view'])->json('id');
        // Who may correct posted invoices and bills, per person: the custom role chosen without it may not.
        $people = collect($this->getJson('/api/v1/members', $this->h)->assertOk()->json('data'));
        $this->assertFalse($people->firstWhere('id', $m)['can_correct']);
        $this->assertTrue($people->firstWhere('me', true)['can_correct']);
        // Other roles keep only their name's permissions, whatever is sent.
        $this->postJson('/api/v1/members/invitations', ['email' => 'dawit@example.test', 'name' => 'Dawit', 'role' => 'viewer', 'permissions' => ['payments.pay']], $this->h)
            ->assertCreated()->assertJsonPath('permissions', []);

        $tigist = User::query()->where('email', 'tigist@example.test')->sole();
        $token = $this->tokenFor($tigist);
        $this->signedOut();
        $this->postJson('/api/v1/auth/invitation/accept', ['token' => $token, 'password' => 'Coffee-Ledger-2026!'])->assertOk();

        $this->actingAs($tigist, 'sanctum');
        $this->getJson('/api/v1/invoices', $this->h)->assertOk();
        $this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31', $this->h)->assertOk();
        $this->postJson('/api/v1/invoices', [], $this->h)->assertForbidden();
        $this->getJson('/api/v1/members', $this->h)->assertForbidden();

        $this->actingAs($this->me, 'sanctum');
        $this->putJson("/api/v1/members/{$m}", ['role' => 'custom', 'permissions' => ['invoices.view', 'invoices.create']], $this->h)->assertOk()
            ->assertJsonPath('permissions', ['company.view', 'invoices.create', 'invoices.view']);
        $this->actingAs($tigist, 'sanctum');
        $this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31', $this->h)->assertForbidden();
        $this->postJson('/api/v1/invoices', [], $this->h)->assertUnprocessable();   // allowed now: only the empty draft is refused

        $this->actingAs($this->me, 'sanctum');
        $this->putJson("/api/v1/members/{$m}", ['role' => 'viewer'], $this->h)->assertOk()->assertJsonPath('permissions', []);
        $this->assertSame('[]', $this->owner()->table('memberships')->where('id', $m)->value('permissions'));
        $changes = $this->owner()->table('audit_log')->where(['subject_id' => $m, 'action' => 'member_role_changed'])->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertStringContainsString('reports.view', (string) $changes[0]->before);
        $this->assertStringContainsString('invoices.create', (string) $changes[0]->after);
    }
}
