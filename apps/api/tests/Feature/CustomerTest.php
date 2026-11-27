<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2, customers: added by sales staff, edited and archived by accountants, owners and admins, never deleted;
 * tax ID checked per country; every change audited; the open balance is what posted invoices still owe.
 * Roles: PermissionMatrixTest. Other companies: TenantIsolationTest.
 */
final class CustomerTest extends TestCase
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
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // one posted invoice of 1,050.00, 50.00 paid
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    public function test_sales_staff_add_a_customer_and_cannot_edit_it(): void
    {
        $this->actingAs($this->member('sales'), 'sanctum');

        $id = $this->postJson('/api/v1/customers', [
            'name' => 'Sheger Grand Hotel', 'tax_id' => '0012345678', 'email' => 'ap@sheger.example', 'payment_terms_days' => 15,
            'credit_limit' => '250000', 'address' => ['line1' => 'Bole Road', 'city' => 'Addis Ababa', 'country' => 'ET'],
        ], $this->h)->assertCreated()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('can', ['create' => true, 'edit' => false])
            ->assertJsonPath('open_balance', '0.00');
        // jsonb keeps the keys, not their order.
        $this->assertEquals(['line1' => 'Bole Road', 'city' => 'Addis Ababa', 'country' => 'ET'], $id->json('address'));
        $id = $id->json('id');

        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Renamed'], $this->h)->assertForbidden();
        $this->postJson('/api/v1/customers', ['name' => 'Bad TIN', 'tax_id' => '12'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/v1/customers', ['name' => 'Born archived', 'is_active' => false], $this->h)->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'subject_type' => 'customer', 'action' => 'created'])->count());
    }

    public function test_the_owner_edits_and_archives_a_customer_and_the_change_is_audited(): void
    {
        $id = $this->c['contact'];
        $this->putJson("/api/v1/customers/{$id}", ['tax_exempt' => true], $this->h)->assertUnprocessable()->assertJsonValidationErrors('tax_exempt_reason');
        $this->putJson("/api/v1/customers/{$id}", ['tax_exempt' => true, 'tax_exempt_reason' => 'Embassy, certificate 12/2026', 'payment_terms_days' => 0], $this->h)
            ->assertOk()->assertJsonPath('tax_exempt', true)->assertJsonPath('payment_terms_days', 0);
        $this->putJson("/api/v1/customers/{$id}", ['is_active' => false], $this->h)->assertOk()->assertJsonPath('is_active', false);

        // Archived customers leave the list unless asked for, and are never deleted.
        $this->assertSame([], array_column($this->getJson('/api/v1/customers', $this->h)->json('data'), 'id'));
        $this->assertSame([$id], array_column($this->getJson('/api/v1/customers?include_archived=1', $this->h)->json('data'), 'id'));

        $trail = $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'subject_type' => 'customer', 'subject_id' => $id])
            ->orderBy('id')->get(['action', 'before', 'after']);
        $this->assertSame(['updated', 'updated'], $trail->pluck('action')->all());
        $this->assertEquals(['tax_exempt' => false, 'tax_exempt_reason' => null, 'payment_terms_days' => 30, 'payment_terms_id' => null], json_decode($trail[0]->before, true));
        // 0 days is the company's Due on receipt term (109), recorded with it.
        $this->assertSame($this->owner()->table('payment_terms')->where(['company_id' => $this->c['company'], 'name' => 'Due on receipt'])->value('id'),
            json_decode($trail[0]->after, true)['payment_terms_id']);
    }

    public function test_the_open_balance_is_what_posted_invoices_still_owe(): void
    {
        $this->getJson("/api/v1/customers/{$this->c['contact']}", $this->h)->assertOk()
            ->assertJsonPath('open_balance', '1000.00')
            ->assertJsonCount(1, 'recent_invoices')
            ->assertJsonPath('recent_invoices.0.status', 'part_paid');
        $this->getJson('/api/v1/customers?search=abyssinia', $this->h)->assertOk()->assertJsonPath('data.0.open_balance', '1000.00');
        $this->getJson('/api/v1/customers?search=%25', $this->h)->assertOk()->assertJsonCount(0, 'data'); // % is a character, not a wildcard
    }

    public function test_a_supplier_is_not_a_customer_here(): void
    {
        $vendor = (string) Str::uuid();
        $this->owner()->table('contacts')->insert(['id' => $vendor, 'company_id' => $this->c['company'], 'name' => 'Coffee farm', 'is_vendor' => true]);

        $this->getJson("/api/v1/customers/{$vendor}", $this->h)->assertNotFound();
        $this->putJson("/api/v1/customers/{$vendor}", ['name' => 'X'], $this->h)->assertNotFound();
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }
}
