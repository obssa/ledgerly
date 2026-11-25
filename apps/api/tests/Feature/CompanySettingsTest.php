<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, company profile and fiscal settings: who may read and change them, the rule that the foundation of the
 * books is fixed once anything is posted, lost-update protection, the period lock, and the audit record of each.
 * Isolation between companies is covered for these routes by TenantIsolationTest; roles by PermissionMatrixTest.
 */
final class CompanySettingsTest extends TestCase
{
    use RefreshLedgerDatabase;

    private User $ownerUser;

    private string $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerUser = $this->createUser('Hana', 'hana@example.test');
        $this->actingAs($this->ownerUser, 'sanctum');
        $this->company = $this->postJson('/api/v1/companies', [
            'legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7,
            'legal_form' => 'private_limited', 'city' => 'Addis Ababa',
        ])->assertCreated()->json('id');
    }

    public function test_the_owner_reads_and_changes_the_profile_and_the_change_is_audited(): void
    {
        $settings = $this->settings()->assertOk()
            ->assertJsonPath('legal_form', 'private_limited')
            ->assertJsonPath('city', 'Addis Ababa')
            ->assertJsonPath('books_started', false)
            ->assertJsonPath('locked_fields', [])
            ->assertJsonPath('options.tax_id_label', 'TIN')
            ->assertJsonPath('can', ['manage' => true, 'close_periods' => true, 'reopen_periods' => true, 'enter_rates' => true, 'manage_dimensions' => true, 'test_books' => false]);

        $this->save(['version' => $settings->json('version'), 'trading_name' => 'Abyssinia', 'tin' => '0012345678', 'address_line1' => 'Bole Road'])
            ->assertOk()
            ->assertJsonPath('trading_name', 'Abyssinia')
            ->assertJsonPath('tin', '0012345678');

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'action' => 'updated', 'subject_type' => 'company'])->sole();
        $this->assertSame($this->ownerUser->getKey(), $audit->user_id);
        $this->assertEquals(['trading_name' => null, 'tin' => null, 'address_line1' => null], $this->decoded($audit->before));
        $this->assertEquals(['trading_name' => 'Abyssinia', 'tin' => '0012345678', 'address_line1' => 'Bole Road'], $this->decoded($audit->after));
    }

    public function test_a_save_based_on_an_old_read_is_refused(): void
    {
        $version = $this->settings()->json('version');
        $this->save(['version' => $version, 'trading_name' => 'First'])->assertOk();

        $this->save(['version' => $version, 'trading_name' => 'Second'])->assertStatus(409)->assertJsonPath('type', 'StaleSettings');
        $this->assertSame('First', $this->owner()->table('companies')->where('id', $this->company)->value('trading_name'));
    }

    public function test_the_tax_id_is_checked_against_the_country(): void
    {
        $this->save(['version' => $this->settings()->json('version'), 'tin' => 'ABC'])->assertUnprocessable()->assertJsonValidationErrors('tin');
    }

    public function test_before_anything_is_posted_the_foundation_can_change_and_the_first_fiscal_year_follows(): void
    {
        $this->save([
            'version' => $this->settings()->json('version'),
            'base_currency' => 'USD', 'fiscal_year_start_month' => 1, 'conversion_date' => '2026-03-31',
        ])->assertOk()
            ->assertJsonPath('base_currency', 'USD')
            ->assertJsonPath('fiscal_years', [['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']]);
    }

    public function test_once_anything_is_posted_the_foundation_is_fixed(): void
    {
        $this->post_an_entry();
        $settings = $this->settings()->assertJsonPath('books_started', true)
            ->assertJsonPath('locked_fields', ['base_currency', 'calendar', 'fiscal_year_start_month', 'conversion_date']);

        $this->save(['version' => $settings->json('version'), 'base_currency' => 'USD', 'fiscal_year_start_month' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['base_currency', 'fiscal_year_start_month']);
        $this->assertSame('ETB', $this->owner()->table('companies')->where('id', $this->company)->value('base_currency'));

        // The profile still changes.
        $this->save(['version' => $settings->json('version'), 'trading_name' => 'Abyssinia'])->assertOk();
    }

    public function test_the_country_cannot_be_changed_here(): void
    {
        $this->save(['version' => $this->settings()->json('version'), 'country_code' => 'KE', 'trading_name' => 'X'])->assertOk();
        $this->assertSame('ET', $this->owner()->table('companies')->where('id', $this->company)->value('country_code'));
    }

    public function test_an_accountant_closes_periods_and_only_an_owner_reopens_them_with_a_reason(): void
    {
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'sanctum');
        $this->settings()->assertOk()->assertJsonPath('can', ['manage' => false, 'close_periods' => true, 'reopen_periods' => false, 'enter_rates' => true, 'manage_dimensions' => true, 'test_books' => false]);
        $this->save(['version' => 'x', 'trading_name' => 'Mine'])->assertForbidden();

        $this->lock(['books_closed_until' => '2026-09-30'])->assertOk()->assertJsonPath('books_closed_until', '2026-09-30');
        $this->lock(['books_closed_until' => '2026-10-31'])->assertOk();
        $this->lock(['books_closed_until' => '2026-09-30', 'reason' => 'Late bill'])->assertForbidden();
        $this->lock(['books_closed_until' => null, 'reason' => 'Late bill'])->assertForbidden();
        $this->lock(['books_closed_until' => now('Africa/Addis_Ababa')->addDay()->format('Y-m-d')])->assertUnprocessable();

        $this->actingAs($this->ownerUser, 'sanctum');
        $this->lock(['books_closed_until' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->lock(['books_closed_until' => '2026-09-30', 'reason' => 'Late supplier bill for September'])->assertOk();

        $this->assertSame('2026-09-30', $this->owner()->table('companies')->where('id', $this->company)->value('books_closed_until'));
        $trail = $this->owner()->table('audit_log')->where('company_id', $this->company)->whereIn('action', ['period_closed', 'period_reopened'])
            ->orderBy('id')->get(['action', 'user_id', 'reason', 'after']);
        $this->assertSame(['period_closed', 'period_closed', 'period_reopened'], $trail->pluck('action')->all());
        $this->assertSame([$accountant->getKey(), $accountant->getKey(), $this->ownerUser->getKey()], $trail->pluck('user_id')->all());
        $this->assertSame('Late supplier bill for September', $trail->last()->reason);
        $this->assertSame(['books_closed_until' => '2026-09-30'], $this->decoded($trail->last()->after));
    }

    public function test_a_closed_period_refuses_postings(): void
    {
        $this->lock(['books_closed_until' => '2026-11-10'])->assertOk();
        $this->post_an_entry('2026-11-05', expectStatus: 422);
        $this->post_an_entry('2026-11-11');
    }

    private function settings(): TestResponse
    {
        return $this->getJson('/api/v1/company', ['X-Company-Id' => $this->company]);
    }

    /** @param array<string, mixed> $body */
    private function save(array $body): TestResponse
    {
        return $this->putJson('/api/v1/company', $body, ['X-Company-Id' => $this->company]);
    }

    /** @param array<string, mixed> $body */
    private function lock(array $body): TestResponse
    {
        return $this->putJson('/api/v1/company/period-lock', $body, ['X-Company-Id' => $this->company]);
    }

    private function post_an_entry(string $date = '2026-11-04', int $expectStatus = 201): void
    {
        $account = fn (string $key) => $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => $key])->value('id');
        $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => 'Opening cash', 'lines' => [
            ['account_id' => $account('bank'), 'debit' => '1000'], ['account_id' => $account('opening_balance_equity'), 'credit' => '1000'],
        ]], ['X-Company-Id' => $this->company])->assertStatus($expectStatus);
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->company, 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function decoded(?string $json): array
    {
        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }
}
