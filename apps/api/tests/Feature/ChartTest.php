<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: the chart of accounts, managed by the accountant. */
final class ChartTest extends TestCase
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

    public function test_the_chart_lists_accounts_with_balances_under_their_parents(): void
    {
        $chart = collect($this->getJson('/api/v1/chart', $this->h)->assertOk()->assertJsonPath('can.manage', true)->json('data'));
        $bank = $chart->firstWhere('id', $this->c['bank']);
        $this->assertSame([true, true], [$bank['is_money'], $bank['has_entries']]);
        $this->assertNotSame('0.00', $bank['balance']);
        $this->assertTrue($chart->firstWhere('system_key', 'accounts_receivable')['is_system']);
        $this->assertSame($chart->pluck('code')->sort()->values()->all(), $chart->where('depth', 0)->pluck('code')->sort()->values()->all(), 'a fresh chart is flat');

        // A group with two sub-accounts: they follow it, one level down.
        $group = $this->postJson('/api/v1/accounts', ['code' => '6150', 'name' => 'Vehicle costs', 'type' => 'expense', 'kind' => 'operating'], $this->h)
            ->assertCreated()->assertJsonPath('balance', '0.00')->json('id');
        $this->postJson('/api/v1/accounts', ['code' => '6151', 'name' => 'Fuel', 'type' => 'expense', 'parent_id' => $group], $this->h)->assertCreated();
        $this->postJson('/api/v1/accounts', ['code' => '6152', 'name' => 'Repairs', 'type' => 'expense', 'parent_id' => $group], $this->h)->assertCreated();
        $codes = collect($this->getJson('/api/v1/chart', $this->h)->json('data'))->map(fn ($a) => [$a['code'], $a['depth']]);
        $at = $codes->search(['6150', 0]);
        $this->assertSame([['6150', 0], ['6151', 1], ['6152', 1]], $codes->slice($at, 3)->values()->all());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $group, 'action' => 'created'])->count());
    }

    public function test_what_the_chart_refuses(): void
    {
        $add = fn (array $body) => $this->postJson('/api/v1/accounts', [...['code' => '6160', 'name' => 'Something', 'type' => 'expense'], ...$body], $this->h);
        $add(['code' => '6100'])->assertUnprocessable()->assertJsonValidationErrors('code');                 // taken (rent)
        $add(['code' => 'six one'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $add(['kind' => 'bank'])->assertUnprocessable()->assertJsonValidationErrors('kind');                   // under Banking
        $add(['kind' => 'loan'])->assertUnprocessable()->assertJsonValidationErrors('kind');                   // a liability kind
        $add(['parent_id' => $this->c['bank']])->assertUnprocessable()->assertJsonValidationErrors('parent_id'); // an asset
        $receivable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');

        // System accounts stay active; an account with money in it is not archived; an empty one is, and comes back.
        $this->putJson("/api/v1/accounts/{$receivable}", ['is_active' => false], $this->h)->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson("/api/v1/accounts/{$receivable}", ['kind' => 'deposit'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->putJson("/api/v1/accounts/{$this->c['rent']}", ['is_active' => false], $this->h)->assertUnprocessable()->assertJsonValidationErrors('account');
        $spare = $add([])->assertCreated()->json('id');
        $this->putJson("/api/v1/accounts/{$spare}", ['is_active' => false, 'name' => '  Spare  '], $this->h)->assertOk()
            ->assertJsonPath('is_active', false)->assertJsonPath('name', 'Spare');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $spare, 'action' => 'archived'])->count());
        $this->putJson("/api/v1/accounts/{$spare}", ['is_active' => true, 'code' => '6161'], $this->h)->assertOk()->assertJsonPath('code', '6161');

        // Renaming and re-coding the bank is fine; its kind is Banking's.
        $this->putJson("/api/v1/accounts/{$this->c['bank']}", ['name' => 'CBE current account'], $this->h)->assertOk();
        $this->putJson("/api/v1/accounts/{$this->c['bank']}", ['kind' => 'deposit'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_bookkeepers_and_viewers_read_the_chart_and_cannot_change_it(): void
    {
        $user = $this->createUser('Bookkeeper', 'bk@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'bookkeeper']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/chart', $this->h)->assertOk()->assertJsonPath('can.manage', false);
        $this->postJson('/api/v1/accounts', ['code' => '6170', 'name' => 'x', 'type' => 'expense'], $this->h)->assertForbidden();
    }
}
