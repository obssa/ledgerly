<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\DemoSetup;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Test companies (104): real books the operator keeps for trying Ledgerly out. The operator makes one, with its owners,
 * out of the shared demo (`ledgerly:test-company`); its active owners, and nobody else, may start it fresh and fill it
 * with the sample books. Nothing is deleted, and every change is in the audit log.
 */
final class TestCompanyTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private string $accountsFile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->accountsFile = sys_get_temp_dir() . '/ledgerly-test-accounts-' . Str::random(8) . '.txt';
    }

    protected function tearDown(): void
    {
        @unlink($this->accountsFile);
        parent::tearDown();
    }

    private function makeTestCompany(string $company): int
    {
        return $this->artisan('ledgerly:test-company', [
            'company' => $company,
            '--owner' => ['obssa@example.test|Obssa Test 1', 'henok@example.test|Henok Test 1'],
            '--accounts-file' => $this->accountsFile,
            '--by' => 'tests',
        ])->run();
    }

    public function test_the_operator_takes_the_demo_company_out_of_the_demo_with_two_owners(): void
    {
        config(['demo.enabled' => true]);
        app(DemoSetup::class)->run(fn () => null);
        $company = (string) $this->owner()->table('demo_accounts')->where('role', 'owner')->value('company_id');
        $demoUsers = $this->owner()->table('demo_accounts')->pluck('user_id')->map(fn ($u) => (string) $u)->all();
        $this->assertNotEmpty($demoUsers);

        $this->assertSame(0, $this->makeTestCompany($company));

        // Two new accounts, each with its own generated password, written to the file only.
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->accountsFile))));
        $this->assertCount(2, $lines);
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->accountsFile)), -3));
        $passwords = [];
        foreach ($lines as $line) {
            [$name, $email, $password] = explode("\t", $line);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $password);
            $passwords[$email] = $password;
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertNotNull($user->email_verified_at);
            $this->assertSame($name, $user->name);
            $this->assertSame('active', $this->owner()->table('memberships')->where(['company_id' => $company, 'user_id' => $user->getKey(), 'role' => 'owner'])->value('status'));
        }
        $this->assertNotSame($passwords['obssa@example.test'], $passwords['henok@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'obssa@example.test', 'password' => $passwords['obssa@example.test']])->assertOk()->assertJsonStructure(['token']);

        // Out of the demo: no demo accounts, their memberships switched off, their sign-ins gone, their addresses retired.
        $this->assertSame(0, $this->owner()->table('demo_accounts')->count());
        $this->assertSame(0, $this->owner()->table('memberships')->where('company_id', $company)->whereIn('user_id', $demoUsers)->where('status', 'active')->count());
        $this->assertSame(0, $this->owner()->table('personal_access_tokens')->whereIn('tokenable_id', $demoUsers)->count());
        $this->assertSame(0, $this->owner()->table('users')->where('email', 'owner@demo.ledgerly.invalid')->count());

        // Its own name, marked as a test company, and all of it in the audit log.
        $this->assertSame('Abyssinia Coffee Export', $this->owner()->table('companies')->where('id', $company)->value('legal_name'));
        $this->assertTrue($this->owner()->table('test_companies')->where('company_id', $company)->exists());
        $actions = $this->owner()->table('audit_log')->where('company_id', $company)->pluck('action')->all();
        foreach (['member_added', 'demo_accounts_removed', 'company_updated', 'test_company_marked'] as $action) {
            $this->assertContains($action, $actions);
        }
        $this->assertSame(2, collect($actions)->filter(fn ($a) => $a === 'member_added')->count());

        // Safe to run again: no new accounts, nothing written, nothing doubled.
        $this->assertSame(0, $this->makeTestCompany($company));
        $this->assertCount(2, array_filter(explode("\n", (string) file_get_contents($this->accountsFile))));
        $this->assertSame(2, $this->owner()->table('memberships')->where(['company_id' => $company, 'role' => 'owner', 'status' => 'active'])->count());

        // The demo can still be switched back on later: its setup makes new demo accounts in a new demo company.
        app(DemoSetup::class)->run(fn () => null);
        $newDemo = (string) $this->owner()->table('demo_accounts')->where('role', 'owner')->value('company_id');
        $this->assertNotSame($company, $newDemo);
        $this->assertTrue($this->owner()->table('test_companies')->where('company_id', $company)->exists());
    }

    public function test_an_owner_of_a_test_company_starts_it_fresh_and_fills_it_with_the_sample(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Trial Coffee');
        $owner = $this->lastUser;
        $old = $c['company'];
        $h = fn (string $company) => ['X-Company-Id' => $company];
        $admin = $this->createUser('Admin', 'admin@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $old, 'user_id' => $admin->getKey(), 'role' => 'admin', 'status' => 'active']);
        $this->owner()->table('test_companies')->insert(['company_id' => $old, 'marked_by' => 'tests']);

        // What the settings screen is told.
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/company', $h($old))->assertOk()
            ->assertJsonPath('test_company', true)->assertJsonPath('can.test_books', true);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/company', $h($old))->assertOk()->assertJsonPath('can.test_books', false);
        // An admin is not an owner: the buttons do not exist for them.
        $this->postJson('/api/v1/demo/start-fresh', [], $h($old))->assertNotFound();
        $this->postJson('/api/v1/demo/fill', [], $h($old))->assertNotFound();
        $this->app['auth']->forgetGuards();

        // It has books: filling is refused until it is started fresh.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/demo/fill', [], $h($old))->assertUnprocessable()->assertJsonPath('type', 'NotEmpty');
        $fresh = $this->postJson('/api/v1/demo/start-fresh', [], $h($old))->assertCreated()->json('company_id');
        $this->assertNotSame($old, $fresh);

        // The old books are kept in full, read-only; the new company is empty, a test company, with every member.
        $this->assertSame('archived', $this->owner()->table('companies')->where('id', $old)->value('status'));
        $this->assertGreaterThan(0, $this->owner()->table('journal_entries')->where('company_id', $old)->count());
        $this->assertSame(0, $this->owner()->table('journal_entries')->where('company_id', $fresh)->count());
        $this->assertTrue($this->owner()->table('test_companies')->where('company_id', $fresh)->exists());
        $this->assertSame('admin', $this->owner()->table('memberships')->where(['company_id' => $fresh, 'user_id' => $admin->getKey(), 'status' => 'active'])->value('role'));
        $this->assertSame('Trial Coffee', $this->owner()->table('companies')->where('id', $fresh)->value('legal_name'));

        // Again at once: refused for a minute.
        $this->postJson('/api/v1/demo/start-fresh', [], $h($fresh))->assertUnprocessable()->assertJsonPath('type', 'TooSoon');

        // Fill: refused, and said why, until the books have a conversion date (the sample's opening balances are then).
        $this->postJson('/api/v1/demo/fill', [], $h($fresh))->assertUnprocessable()->assertJsonPath('type', 'NoConversionDate');
        $this->owner()->table('companies')->where('id', $fresh)->update(['conversion_date' => '2026-07-08']);
        // Then the sample books go in (the queue runs at once here), balanced, and the request is in the audit log.
        $this->postJson('/api/v1/demo/fill', [], $h($fresh))->assertStatus(202);
        $this->assertGreaterThan(0, $this->owner()->table('documents')->where(['company_id' => $fresh, 'type' => 'invoice'])->count());
        $this->assertSame([], $this->owner()->select('select * from ledger_verify(?)', [$fresh]));
        $this->assertTrue($this->owner()->table('audit_log')->where(['company_id' => $fresh, 'action' => 'sample_data_requested'])->exists());
        $this->assertTrue($this->owner()->table('audit_log')->where(['company_id' => $old, 'action' => 'company_archived'])->exists());
    }

    public function test_the_buttons_stay_closed_to_other_companies(): void
    {
        $a = $this->companyWithRecords('a@example.test', 'Test Books A');
        $ownerA = $this->lastUser;
        $this->owner()->table('test_companies')->insert(['company_id' => $a['company'], 'marked_by' => 'tests']);
        $this->app['auth']->forgetGuards();
        $b = $this->companyWithRecords('b@example.test', 'Real Books B');

        // The owner of a company that is not a test company: no buttons, and told so.
        $this->getJson('/api/v1/company', ['X-Company-Id' => $b['company']])->assertOk()
            ->assertJsonPath('test_company', false)->assertJsonPath('can.test_books', false);
        $this->postJson('/api/v1/demo/start-fresh', [], ['X-Company-Id' => $b['company']])->assertNotFound();
        $this->postJson('/api/v1/demo/fill', [], ['X-Company-Id' => $b['company']])->assertNotFound();
        // Nor in company A, which is not theirs.
        $this->postJson('/api/v1/demo/start-fresh', [], ['X-Company-Id' => $a['company']])->assertForbidden();
        $this->app['auth']->forgetGuards();

        // A's owner cannot reach B through A's mark either.
        $this->actingAs($ownerA, 'sanctum')->postJson('/api/v1/demo/start-fresh', [], ['X-Company-Id' => $b['company']])->assertForbidden();
        $this->assertNotSame('archived', $this->owner()->table('companies')->where('id', $b['company'])->value('status'));
        $this->assertNotSame('archived', $this->owner()->table('companies')->where('id', $a['company'])->value('status'));
    }
}
