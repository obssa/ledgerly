<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End to end through the HTTP API on PostgreSQL: set up a company, invoice a customer, approve,
 * and check the ledger and the e-invoice clearance.
 *
 * Uses DatabaseMigrations (not RefreshDatabase): the balance check is a DEFERRED constraint trigger and
 * only fires when a transaction commits, which a per-test rollback would never do.
 */
final class InvoiceFlowTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_can_invoice_and_the_ledger_balances(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $user = User::query()->create(['name' => 'Hana', 'email' => 'hana@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        $companyId = $this->postJson('/api/v1/companies', [
            'legal_name' => 'Abyssinia Coffee Traders PLC',
            'country_code' => 'ET',
            'tin' => '0098765432',
            'vat_registered' => true,
            'fiscal_year_start_month' => 7,
            'conversion_date' => '2026-10-31',
        ])->assertCreated()->json('id');

        $headers = ['X-Company-Id' => $companyId];
        $sales = DB::table('accounts')->where(['company_id' => $companyId, 'system_key' => 'sales'])->value('id');
        $vat15 = DB::table('tax_rates')->where(['company_id' => $companyId, 'code' => 'VAT15'])->value('id');
        $customer = DB::table('contacts')->insertGetId([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $companyId,
            'name' => 'Sheger Grand Hotel', 'is_customer' => true, 'tax_id' => '0012345678',
        ]);

        $invoiceId = $this->postJson('/api/v1/invoices', [
            'contact_id' => $customer,
            'number' => 'INV-2019-0419',
            'issue_date' => '2026-11-02',
            'due_date' => '2026-12-02',
            'currency' => 'ETB',
            'lines' => [
                ['description' => 'Roasted coffee 1 kg · Yirgacheffe', 'quantity' => '240', 'unit_price' => '1150', 'account_id' => $sales, 'tax_rate_id' => $vat15],
                ['description' => 'Roasted coffee 1 kg · Sidamo', 'quantity' => '160', 'unit_price' => '980', 'account_id' => $sales, 'tax_rate_id' => $vat15],
                ['description' => 'Delivery · Addis Ababa', 'quantity' => '1', 'unit_price' => '3500', 'account_id' => $sales, 'tax_rate_id' => $vat15],
            ],
        ], $headers)->assertCreated()->assertJsonPath('total', '501745.0000')->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/approve", [], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('einvoice_submissions.0.status', 'cleared');

        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $headers)->assertOk()->json('data'));
        $this->assertSame('501745.0000', $tb->firstWhere('code', '1200')['debit']);
        $this->assertSame('65445.0000', $tb->firstWhere('code', '2300')['credit']);
        $this->assertSame('436300.0000', $tb->firstWhere('code', '4000')['credit']);

        // Posted invoices are frozen.
        $this->putJson("/api/v1/invoices/{$invoiceId}", ['contact_id' => $customer, 'number' => 'X', 'issue_date' => '2026-11-02', 'currency' => 'ETB', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $sales]]], $headers)
            ->assertStatus(422);
    }

    public function test_users_cannot_open_companies_they_do_not_belong_to(): void
    {
        $user = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/invoices', ['X-Company-Id' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->getJson('/api/v1/invoices')->assertStatus(400);
    }
}
