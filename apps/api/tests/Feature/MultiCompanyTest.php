<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, multi-company: several companies under one organisation, listed for the company switcher. Only the
 * organisation's creator or an owner or admin of one of its companies adds a company to it.
 */
final class MultiCompanyTest extends TestCase
{
    use RefreshLedgerDatabase;

    private const COMPANY = ['country_code' => 'ET', 'fiscal_year_start_month' => 7];

    public function test_an_owner_adds_a_second_company_to_the_same_organisation_and_sees_both(): void
    {
        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
        $first = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', ...self::COMPANY])->assertCreated();
        $organisation = $first->json('organisation_id');

        $second = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Roastery', 'organisation_id' => $organisation, ...self::COMPANY])
            ->assertCreated()->assertJsonPath('organisation_id', $organisation);

        $list = $this->getJson('/api/v1/companies')->assertOk()->json('data');
        $this->assertSame(['Abyssinia Coffee', 'Abyssinia Roastery'], array_column($list, 'legal_name'));
        $this->assertSame([$organisation, $organisation], array_column($list, 'organisation_id'));

        // Each company has its own books.
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(51, $this->owner()->table('accounts')->where('company_id', $second->json('id'))->count());
    }

    public function test_someone_else_cannot_add_a_company_to_an_organisation(): void
    {
        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
        $company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', ...self::COMPANY])->assertCreated();

        // A stranger.
        $this->actingAs($this->createUser('Other', 'other@example.test'), 'sanctum');
        $this->postJson('/api/v1/companies', ['legal_name' => 'Planted', 'organisation_id' => $company->json('organisation_id'), ...self::COMPANY])
            ->assertForbidden()->assertJsonPath('type', 'NotOrganisationAdmin');

        // A bookkeeper of one of its companies.
        $bookkeeper = $this->createUser('Bookkeeper', 'bk@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $company->json('id'), 'user_id' => $bookkeeper->getKey(), 'role' => 'bookkeeper']);
        $this->actingAs($bookkeeper, 'sanctum');
        $this->postJson('/api/v1/companies', ['legal_name' => 'Side business', 'organisation_id' => $company->json('organisation_id'), ...self::COMPANY])
            ->assertForbidden();

        $this->assertSame(1, $this->owner()->table('companies')->where('organisation_id', $company->json('organisation_id'))->count());
    }
}
