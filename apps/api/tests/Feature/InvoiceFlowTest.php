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

        // A posted invoice already registered with the tax authority is corrected with a credit note, not edited.
        $this->putJson("/api/v1/invoices/{$invoiceId}", ['reason' => 'Wrong price', 'contact_id' => $customer, 'number' => 'INV-2019-0419', 'issue_date' => '2026-11-02', 'currency' => 'ETB', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'account_id' => $sales]]], $headers)
            ->assertStatus(422)
            ->assertJsonPath('type', 'LedgerException');
    }

    public function test_editing_a_posted_invoice_keeps_full_history(): void
    {
        // Ethiopia's connector keeps submissions queued until the Ministry's spec exists, so the invoice is not cleared.
        config(['einvoicing.force_sandbox' => false, 'queue.default' => 'sync']);
        $owner = User::query()->create(['name' => 'Hana', 'email' => 'hana2@example.test', 'password' => 'secret-password']);
        $this->actingAs($owner, 'sanctum');
        $companyId = $this->postJson('/api/v1/companies', ['legal_name' => 'Harar Traders', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->assertCreated()->json('id');
        $headers = ['X-Company-Id' => $companyId];
        $sales = DB::table('accounts')->where(['company_id' => $companyId, 'system_key' => 'sales'])->value('id');
        $vat15 = DB::table('tax_rates')->where(['company_id' => $companyId, 'code' => 'VAT15'])->value('id');
        $customer = (string) \Illuminate\Support\Str::uuid();
        DB::table('contacts')->insert(['id' => $customer, 'company_id' => $companyId, 'name' => 'Sheger Grand Hotel', 'is_customer' => true]);

        $invoice = fn (string $qty, ?string $reason = null) => array_filter([
            'reason' => $reason, 'contact_id' => $customer, 'number' => 'INV-0001', 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Roasted coffee 1 kg', 'quantity' => $qty, 'unit_price' => '1150', 'account_id' => $sales, 'tax_rate_id' => $vat15]],
        ]);
        $id = $this->postJson('/api/v1/invoices', $invoice('240'), $headers)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $headers)->assertOk();

        // No reason, no edit.
        $this->putJson("/api/v1/invoices/{$id}", $invoice('200'), $headers)->assertStatus(422);

        // Edit with a reason: 240 → 200 kg. 200 × 1,150 = 230,000 + 15% VAT 34,500 = 264,500.
        $this->putJson("/api/v1/invoices/{$id}", $invoice('200', 'Customer returned 40 kg before delivery'), $headers)
            ->assertOk()->assertJsonPath('total', '264500.0000')->assertJsonPath('status', 'posted');

        // History: version 1 is the original, and the audit record carries the reason and the three entries.
        $history = $this->getJson("/api/v1/invoices/{$id}/history", $headers)->assertOk();
        $this->assertSame(1, $history->json('versions.0.version'));
        $this->assertSame('317400.0000', $history->json('versions.0.snapshot.total'));
        $edit = collect($history->json('audit'))->firstWhere('action', 'edited_after_posting');
        $this->assertSame('Customer returned 40 kg before delivery', $edit['reason']);
        $after = json_decode($edit['after'], true);
        $this->assertNotNull($after['reversal_entry_id']);
        $this->assertNotNull($after['corrected_entry_id']);

        // The ledger kept the original, its reversal and the correction; the trial balance shows the net.
        $entries = DB::table('journal_entries')->where('company_id', $companyId)->orderBy('number')->get(['source_type', 'status']);
        $this->assertSame([['invoice', 'reversed'], ['reversal', 'posted'], ['invoice', 'posted']], $entries->map(fn ($e) => [$e->source_type, $e->status])->all());
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $headers)->json('data'));
        $this->assertSame('264500.0000', $tb->firstWhere('code', '1200')['debit']);

        // The audit chain is intact.
        $this->assertNull(DB::selectOne('select audit_log_verify(?) as broken', [$companyId])->broken);

        // Close the books: a bookkeeper can no longer edit, the owner (admin) can, and the lock is restored.
        DB::table('companies')->where('id', $companyId)->update(['books_closed_until' => '2026-11-30']);
        $bookkeeper = User::query()->create(['name' => 'Abebe', 'email' => 'abebe@example.test', 'password' => 'secret-password']);
        DB::table('memberships')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $companyId, 'user_id' => $bookkeeper->getKey(), 'role' => 'bookkeeper']);

        $this->actingAs($bookkeeper, 'sanctum');
        $this->putJson("/api/v1/invoices/{$id}", $invoice('190', 'Late correction'), $headers)->assertStatus(422)->assertJsonPath('type', 'PeriodLocked');

        $this->actingAs($owner, 'sanctum');
        $this->putJson("/api/v1/invoices/{$id}", $invoice('190', 'Auditor adjustment'), $headers)->assertOk();
        $this->assertSame('2026-11-30', DB::table('companies')->where('id', $companyId)->value('books_closed_until'));
        $actions = DB::table('audit_log')->where('company_id', $companyId)->orderBy('id')->pluck('action')->all();
        $this->assertContains('period_reopened', $actions);
        $this->assertContains('period_relocked', $actions);
        $this->assertSame(2, DB::table('document_versions')->where('document_id', $id)->count());
    }

    public function test_users_cannot_open_companies_they_do_not_belong_to(): void
    {
        $user = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/invoices', ['X-Company-Id' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->getJson('/api/v1/invoices')->assertStatus(400);
    }
}
