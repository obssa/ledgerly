<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Start fresh: a new empty company in the same organisation, and the old one archived, not deleted. Archived, it
 * is still read in full, refuses every change, and is listed with its status (the switcher hides it). Only an owner
 * or admin may archive, with the company's name typed exactly.
 */
final class StartFreshTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_company_is_archived_read_only_and_a_fresh_one_starts_empty(): void
    {
        config(['queue.default' => 'sync']);
        $c = $this->companyWithRecords('hana@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->owner()->table('users')->where('id', $c['user'])->update(['email_verified_at' => now()]);

        // A bookkeeper may not; the name must be typed exactly.
        $keeper = $this->createUser('Abebe', 'abebe@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $keeper->getKey(), 'role' => 'bookkeeper', 'permissions' => '[]']);
        $this->actingAs($keeper, 'sanctum');
        $this->postJson('/api/v1/company/archive', ['confirm' => 'Abyssinia Coffee'], $h)->assertForbidden();
        $this->actingAs($this->lastUser, 'sanctum');
        $this->postJson('/api/v1/company/archive', ['confirm' => 'abyssinia coffee'], $h)->assertUnprocessable()->assertJsonValidationErrors('confirm');

        // The fresh company first, in the same organisation; it starts with no invoices.
        $fresh = $this->postJson('/api/v1/companies', ['organisation_id' => $c['organisation'], 'legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])
            ->assertCreated()->json('id');
        $this->getJson('/api/v1/invoices', ['X-Company-Id' => $fresh])->assertOk()->assertJsonCount(0, 'data');

        // Then the old one is archived, with a record of who and why.
        $this->postJson('/api/v1/company/archive', ['confirm' => 'Abyssinia Coffee', 'reason' => 'Started fresh'], $h)->assertOk()->assertJsonPath('status', 'archived');
        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'company_archived'])->first();
        $this->assertSame('Started fresh', $audit->reason);
        $this->assertSame($c['user'], $audit->user_id);

        // Archived: read in full, nothing changes, nothing is deleted.
        $this->assertGreaterThan(0, count($this->getJson('/api/v1/invoices', $h)->assertOk()->json('data')));
        $this->postJson('/api/v1/invoices', [], $h)->assertStatus(423)->assertJsonPath('type', 'CompanyArchived');
        $this->assertGreaterThan(0, $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count());

        // Both are listed, each with its status (the screen hides the archived one).
        $listed = collect($this->getJson('/api/v1/companies')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('archived', $listed[$c['company']]['status']);
        $this->assertNotSame('archived', $listed[$fresh]['status']);
        $this->assertNull($this->owner()->selectOne('select audit_log_verify(?) as broken', [$c['company']])->broken);
    }
}
