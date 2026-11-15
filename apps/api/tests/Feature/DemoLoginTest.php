<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\DemoSetup;
use Illuminate\Database\QueryException;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Demo sign-in (config/demo.php, database/schema/018_demo_accounts.sql): one shared account per role in a demo
 * company, offered only when DEMO_LOGINS is on, and unable to change anything that belongs to the account itself.
 */
final class DemoLoginTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
    }

    private function setUpDemo(): void
    {
        config(['demo.enabled' => true]);
        app(DemoSetup::class)->run(fn () => null);
    }

    /** @return array{token: string, company: string} */
    private function signInAs(string $role): array
    {
        $token = $this->postJson('/api/v1/auth/demo', ['role' => $role])->assertOk()->assertJsonPath('role', $role)->json('token');
        $this->app['auth']->forgetGuards();
        $companies = $this->withToken($token)->getJson('/api/v1/companies')->assertOk()->json('data');
        $this->assertCount(1, $companies, 'a demo account belongs to the demo company only');

        return ['token' => $token, 'company' => $companies[0]['id']];
    }

    public function test_demo_sign_in_is_off_unless_turned_on(): void
    {
        config(['demo.enabled' => false]);
        $this->getJson('/api/v1/auth/demo')->assertNotFound();
        $this->postJson('/api/v1/auth/demo', ['role' => 'owner'])->assertNotFound();

        config(['demo.enabled' => true]);
        $this->getJson('/api/v1/auth/demo')->assertOk()->assertJsonCount(0, 'roles'); // on, but not set up yet
        $this->postJson('/api/v1/auth/demo', ['role' => 'owner'])->assertNotFound();
    }

    public function test_each_role_signs_in_to_the_demo_company_with_sample_books(): void
    {
        $this->setUpDemo();

        $this->getJson('/api/v1/auth/demo')->assertOk()
            ->assertJsonPath('roles.0.role', 'owner')
            ->assertJsonCount(count(config('demo.roles')), 'roles');
        $this->postJson('/api/v1/auth/demo', ['role' => 'custom'])->assertUnprocessable();

        ['token' => $token, 'company' => $company] = $this->signInAs('accountant');
        $h = ['X-Company-Id' => $company];
        $invoices = $this->withToken($token)->getJson('/api/v1/invoices', $h)->assertOk()->json('data');
        $this->assertCount(7, $invoices, 'six posted invoices and a draft');
        $this->withToken($token)->getJson('/api/v1/credit-notes', $h)->assertOk()->assertJsonCount(1, 'data');
        $bills = collect($this->withToken($token)->getJson('/api/v1/bills', $h)->assertOk()->json('data'));
        $this->assertSame(['awaiting_approval', 'paid', 'part_paid', 'posted'], $bills->pluck('status')->sort()->values()->all(), 'suppliers, bills and payments made too');
        $this->withToken($token)->getJson('/api/v1/payments-made', $h)->assertOk()->assertJsonCount(2, 'data');
        $ageing = $this->withToken($token)->getJson('/api/v1/reports/ar-ageing', $h)->assertOk();
        $this->assertNotSame('0.00', $ageing->json('totals.total'));

        $tb = collect($this->withToken($token)->getJson('/api/v1/reports/trial-balance?as_of=' . now()->format('Y-m-d'), $h)->assertOk()->json('data'));
        $this->assertSame(number_format($tb->sum(fn ($r) => (float) $r['debit']), 2, '.', ''), number_format($tb->sum(fn ($r) => (float) $r['credit']), 2, '.', ''), 'the books balance');

        // A role keeps its own limits: the viewer reads but cannot record a payment.
        ['token' => $viewer] = $this->signInAs('viewer');
        $this->withToken($viewer)->getJson('/api/v1/payments-received', $h)->assertOk();
        $this->withToken($viewer)->postJson('/api/v1/customers', ['name' => 'Someone'], $h)->assertForbidden();

        $expires = $this->owner()->table('personal_access_tokens')->where('name', 'demo')->max('expires_at');
        $this->assertLessThanOrEqual(now()->addMinutes((int) config('demo.token_minutes') + 1)->timestamp, strtotime((string) $expires), 'demo sessions are short');
        $this->assertSame(0, $this->owner()->table('personal_access_tokens')->where('name', 'demo-setup')->count(), 'the setup token is gone');
    }

    public function test_a_shared_demo_account_cannot_change_what_belongs_to_the_account(): void
    {
        $this->setUpDemo();
        ['token' => $token] = $this->signInAs('owner');

        foreach ([
            ['POST', '/api/v1/auth/password', ['current_password' => 'x', 'password' => 'a-new-long-password-1']],
            ['POST', '/api/v1/auth/mfa/enrol', []],
            ['DELETE', '/api/v1/auth/mfa', []],
            ['POST', '/api/v1/auth/logout-everywhere', []],
            ['POST', '/api/v1/auth/email/resend', []],
            ['POST', '/api/v1/companies', ['legal_name' => 'Mine now', 'country_code' => 'ET', 'fiscal_year_start_month' => 7]],
        ] as [$method, $uri, $body]) {
            $this->withToken($token)->json($method, $uri, $body)->assertForbidden()->assertJsonPath('type', 'DemoAccount');
        }
        $this->assertSame(1, $this->owner()->table('memberships')->whereIn('user_id', $this->owner()->table('demo_accounts')->where('role', 'owner')->pluck('user_id'))->count());

        // Signing out ends only this visitor's session.
        ['token' => $other] = $this->signInAs('owner');
        $this->app['auth']->forgetGuards(); // each request signs in afresh, as it does outside tests
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_demo_accounts_stay_out_of_real_companies(): void
    {
        $real = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->app['auth']->forgetGuards(); // the fixture signed in its own owner
        $this->setUpDemo();
        ['token' => $token] = $this->signInAs('owner');

        $this->withToken($token)->getJson('/api/v1/invoices', ['X-Company-Id' => $real['company']])->assertForbidden();
        $demoUser = $this->owner()->table('demo_accounts')->where('role', 'viewer')->value('user_id');
        $this->expectException(QueryException::class); // the database refuses it whoever asks
        $this->owner()->table('memberships')->insert(['company_id' => $real['company'], 'user_id' => $demoUser, 'role' => 'viewer']);
    }

    public function test_setup_adds_only_what_is_missing_and_never_takes_over_an_existing_account(): void
    {
        $this->setUpDemo();
        $users = $this->owner()->table('users')->count();
        app(DemoSetup::class)->run(fn () => null);
        $this->assertSame($users, $this->owner()->table('users')->count(), 'a second run adds nothing');
        $this->assertSame(1, $this->owner()->table('companies')->count());

        // A role added to the configuration later gets its account on the next run.
        config(['demo.roles.admin' => ['label' => 'Admin', 'about' => 'Everything']]);
        app(DemoSetup::class)->run(fn () => null);
        $this->assertSame($users + 1, $this->owner()->table('users')->count());
    }

    public function test_someone_who_registered_a_demo_address_does_not_become_a_demo_account(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Squatter', 'email' => 'owner@demo.ledgerly.invalid', 'password' => 'a-long-enough-password-9'])->assertCreated();
        config(['demo.enabled' => true]);

        try {
            app(DemoSetup::class)->run(fn () => null);
            $this->fail('the setup reused an existing account');
        } catch (QueryException) {
            // the demo owner must be a new user: the address is taken, so the setup stops
        }
        $this->assertSame(0, $this->owner()->table('demo_accounts')->count());
    }
}
