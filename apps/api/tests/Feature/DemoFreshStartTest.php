<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\DemoSetup;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * The demo's own buttons (101): its owner starts it fresh (a new, empty demo company, the old one archived, every demo
 * account moved) and fills it with the sample books again. Nowhere else do these exist, and nothing is deleted.
 */
final class DemoFreshStartTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync', 'demo.enabled' => true]);
        app(DemoSetup::class)->run(fn () => null);
    }

    private function tokenFor(string $role): string
    {
        $token = $this->postJson('/api/v1/auth/demo', ['role' => $role])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** The company the demo account works in now: its one company that is not archived. */
    private function liveCompany(string $token): string
    {
        $companies = collect($this->withToken($token)->getJson('/api/v1/companies')->assertOk()->json('data'));
        $this->app['auth']->forgetGuards();
        $live = $companies->where('status', '!=', 'archived')->values();
        $this->assertCount(1, $live, 'a demo account works in one demo company');

        return $live[0]['id'];
    }

    public function test_the_demo_owner_starts_it_fresh_and_fills_it_again(): void
    {
        $owner = $this->tokenFor('owner');
        $old = $this->liveCompany($owner);
        $h = fn (string $company) => ['X-Company-Id' => $company];

        // The sample is there: filling is refused until the demo is started fresh.
        $this->withToken($owner)->postJson('/api/v1/demo/fill', [], $h($old))->assertUnprocessable()->assertJsonPath('type', 'NotEmpty');
        $this->app['auth']->forgetGuards();
        // A demo bookkeeper may not start it fresh.
        $this->withToken($this->tokenFor('bookkeeper'))->postJson('/api/v1/demo/start-fresh', [], $h($old))->assertForbidden();
        $this->app['auth']->forgetGuards();

        $fresh = $this->withToken($owner)->postJson('/api/v1/demo/start-fresh', [], $h($old))->assertCreated()->json('company_id');
        $this->app['auth']->forgetGuards();
        $this->assertNotSame($old, $fresh);

        // Empty: no customers, invoices or entries; the same company details.
        $this->withToken($owner)->getJson('/api/v1/invoices', $h($fresh))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($owner)->getJson('/api/v1/customers', $h($fresh))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($owner)->getJson('/api/v1/company', $h($fresh))->assertOk()->assertJsonPath('legal_name', config('demo.company'))->assertJsonPath('base_currency', 'ETB');
        $this->app['auth']->forgetGuards();
        $this->assertSame(0, $this->owner()->table('journal_entries')->where('company_id', $fresh)->count());

        // The old demo company is kept in full, read-only.
        $this->assertSame('archived', $this->owner()->table('companies')->where('id', $old)->value('status'));
        $this->assertGreaterThan(0, $this->owner()->table('journal_entries')->where('company_id', $old)->count());
        $this->withToken($owner)->postJson('/api/v1/invoices', [], $h($old))->assertStatus(423);
        $this->app['auth']->forgetGuards();

        // Every demo account works in the new company now, with its role.
        $this->assertSame(0, $this->owner()->table('demo_accounts')->where('company_id', $old)->count());
        foreach (['accountant', 'viewer'] as $role) {
            $this->assertSame($fresh, $this->liveCompany($this->tokenFor($role)));
        }

        // Again at once: refused for a minute.
        $this->withToken($this->tokenFor('owner'))->postJson('/api/v1/demo/start-fresh', [], $h($fresh))->assertUnprocessable()->assertJsonPath('type', 'TooSoon');
        $this->app['auth']->forgetGuards();

        // Fill: the sample books go into the new company (the queue runs at once here).
        $this->withToken($owner)->postJson('/api/v1/demo/fill', [], $h($fresh))->assertStatus(202);
        $this->app['auth']->forgetGuards();
        $this->assertGreaterThan(0, $this->owner()->table('documents')->where(['company_id' => $fresh, 'type' => 'invoice'])->count());
        $this->assertGreaterThan(0, $this->owner()->table('documents')->where(['company_id' => $fresh, 'type' => 'bill'])->count());
        $this->assertSame([], $this->owner()->select('select * from ledger_verify(?)', [$fresh]));

        // The deploy's setup leaves a fresh-started demo as its owner made it.
        app(DemoSetup::class)->run(fn () => null);
        $this->assertSame($fresh, $this->owner()->table('demo_accounts')->where('role', 'owner')->value('company_id'));
    }

    public function test_the_demo_buttons_do_not_exist_outside_the_demo(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Real Coffee');
        $this->postJson('/api/v1/demo/start-fresh', [], ['X-Company-Id' => $c['company']])->assertNotFound();
        $this->postJson('/api/v1/demo/fill', [], ['X-Company-Id' => $c['company']])->assertNotFound();
        $this->assertNotSame('archived', $this->owner()->table('companies')->where('id', $c['company'])->value('status'));
    }
}
