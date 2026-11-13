<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End to end through the HTTP API on PostgreSQL: set up a company, invoice a customer, approve,
 * and check the ledger and the e-invoice clearance; then edits with history, idempotency, optimistic
 * locking, the approval step, voiding, read-only subscriptions and any-country setup.
 * NOT YET RUN: the build sandbox could not install Laravel. CI (.github/workflows/ci.yml) runs it.
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
        ], $headers)->assertCreated()->assertJsonPath('total', '501745.00')->json('id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/approve", [], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('einvoice_submissions.0.status', 'queued');

        // Clearance runs after the request's transaction commits (here at once: sync queue).
        $this->getJson("/api/v1/invoices/{$invoiceId}", $headers)
            ->assertOk()
            ->assertJsonPath('einvoice_submissions.0.status', 'cleared');

        // Approving twice is refused by the state machine, and nothing posts twice.
        $this->postJson("/api/v1/invoices/{$invoiceId}/approve", [], $headers)->assertStatus(422)->assertJsonPath('type', 'IllegalTransition');
        $this->assertSame(1, DB::table('journal_entries')->where('company_id', $companyId)->count());
        $this->assertSame([], DB::select('select * from ledger_verify(?)', [$companyId]));

        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $headers)->assertOk()->json('data'));
        $this->assertSame('501745.00', $tb->firstWhere('code', '1200')['debit']);
        $this->assertSame('65445.00', $tb->firstWhere('code', '2300')['credit']);
        $this->assertSame('436300.00', $tb->firstWhere('code', '4000')['credit']);

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
            ->assertOk()->assertJsonPath('total', '264500.00')->assertJsonPath('status', 'posted');

        // History: version 1 is the original, and the audit record carries the reason and the three entries.
        $history = $this->getJson("/api/v1/invoices/{$id}/history", $headers)->assertOk();
        $this->assertSame(1, $history->json('versions.0.version'));
        $this->assertSame('317400.00', $history->json('versions.0.snapshot.total'));
        $edit = collect($history->json('audit'))->firstWhere('action', 'edited_after_posting');
        $this->assertSame('Customer returned 40 kg before delivery', $edit['reason']);
        $after = json_decode($edit['after'], true);
        $this->assertNotNull($after['reversal_entry_id']);
        $this->assertNotNull($after['corrected_entry_id']);

        // The ledger kept the original, its reversal and the correction; the trial balance shows the net.
        $entries = DB::table('journal_entries')->where('company_id', $companyId)->orderBy('number')->get(['source_type', 'status']);
        $this->assertSame([['invoice', 'reversed'], ['reversal', 'posted'], ['invoice', 'posted']], $entries->map(fn ($e) => [$e->source_type, $e->status])->all());
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $headers)->json('data'));
        $this->assertSame('264500.00', $tb->firstWhere('code', '1200')['debit']);

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

    public function test_requests_are_safe_to_repeat_and_safe_against_stale_saves(): void
    {
        config(['queue.default' => 'sync']);
        [$headers, $companyId, $invoice] = $this->companyWithInvoiceData('retry@example.test');

        // Same Idempotency-Key twice: one invoice, and the second response is the stored first one.
        $key = ['Idempotency-Key' => 'b6c1a4f0-0000-4000-8000-000000000001'];
        $first = $this->postJson('/api/v1/invoices', $invoice('10'), $headers + $key)->assertCreated();
        $again = $this->postJson('/api/v1/invoices', $invoice('10'), $headers + $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, DB::table('documents')->where('company_id', $companyId)->count());

        // The same key with a different body is a client bug, not a retry.
        $this->postJson('/api/v1/invoices', $invoice('11'), $headers + $key)->assertStatus(422);

        // Two people open the draft at version 1; the second save is refused.
        $id = $first->json('id');
        $this->putJson("/api/v1/invoices/{$id}", $invoice('12') + ['lock_version' => 1], $headers)->assertOk();
        $this->putJson("/api/v1/invoices/{$id}", $invoice('13') + ['lock_version' => 1], $headers)->assertStatus(409)->assertJsonPath('type', 'StaleDocument');
        $this->assertSame('12.00', DB::table('document_lines')->where('document_id', $id)->value('quantity'));

        // No tenant context is left on the connection after a request.
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.company_id', true) as c")->c);
    }

    public function test_approval_step_void_and_read_only_subscription(): void
    {
        config(['queue.default' => 'sync']);
        [$headers, $companyId, $invoice] = $this->companyWithInvoiceData('flow@example.test');
        $id = $this->postJson('/api/v1/invoices', $invoice('10'), $headers)->assertCreated()->json('id');

        // draft -> awaiting approval -> rejected back to draft -> approved (posted) -> void.
        $this->postJson("/api/v1/invoices/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('status', 'awaiting_approval');
        $this->postJson("/api/v1/invoices/{$id}/reject", ['reason' => 'Wrong customer'], $headers)->assertOk()->assertJsonPath('status', 'draft');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('status', 'posted');
        $this->postJson("/api/v1/invoices/{$id}/void", [], $headers)->assertStatus(422); // a reason is required
        $this->postJson("/api/v1/invoices/{$id}/void", ['reason' => 'Raised in error'], $headers)->assertOk()->assertJsonPath('status', 'void');

        // The void reversed the entry: the trial balance is empty again and the ledger is sound.
        $this->assertSame([], $this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $headers)->assertOk()->json('data'));
        $this->assertSame([], DB::select('select * from ledger_verify(?)', [$companyId]));
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $headers)->assertStatus(422)->assertJsonPath('type', 'IllegalTransition');

        // Suspended subscription: reading works, writing does not.
        $org = DB::table('companies')->where('id', $companyId)->value('organisation_id');
        foreach (['active', 'past_due', 'suspended'] as $status) {
            DB::table('organisations')->where('id', $org)->update(['status' => $status]);
        }
        $this->getJson('/api/v1/invoices', $headers)->assertOk();
        $this->postJson('/api/v1/invoices', $invoice('5'), $headers)->assertStatus(402)->assertJsonPath('type', 'SubscriptionReadOnly');
    }

    public function test_a_company_in_any_country_and_industry_can_be_set_up(): void
    {
        $user = User::query()->create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        // Brazil has no country pack: the default pack applies and the currency must be chosen.
        $this->postJson('/api/v1/companies', ['legal_name' => 'Cafe do Sul', 'country_code' => 'BR', 'fiscal_year_start_month' => 1])->assertStatus(422);
        $id = $this->postJson('/api/v1/companies', [
            'legal_name' => 'Cafe do Sul', 'country_code' => 'BR', 'base_currency' => 'BRL', 'industry' => 'restaurant_hospitality',
            'fiscal_year_start_month' => 1, 'phone' => '+55 11 5555 0100', 'email' => 'oi@cafedosul.example', 'website' => 'https://cafedosul.example',
        ])->assertCreated()->assertJsonPath('base_currency', 'BRL')->json('id');

        $codes = DB::table('accounts')->where('company_id', $id)->pluck('code')->all();
        $this->assertContains('1200', $codes);   // base chart
        $this->assertContains('4020', $codes);   // Food sales, from the industry template
        $this->assertSame(1, DB::table('fiscal_years')->where('company_id', $id)->count());

        // Ethiopia on the Ethiopian calendar: the first fiscal year runs 1 Hamle to 30 Sene.
        $et = $this->postJson('/api/v1/companies', [
            'legal_name' => 'Addis Retail PLC', 'country_code' => 'ET', 'industry' => 'retail_wholesale', 'calendar' => 'ethiopian',
            'fiscal_year_start_month' => 11, 'conversion_date' => '2026-10-31',
        ])->assertCreated()->assertJsonPath('timezone', 'Africa/Addis_Ababa')->json('id');
        $year = DB::table('fiscal_years')->where('company_id', $et)->first();
        $this->assertSame(['2026-07-08', '2027-07-07'], [$year->starts_on, $year->ends_on]);
    }

    /** @return array{0: array<string, string>, 1: string, 2: \Closure} headers, company id, invoice payload builder */
    private function companyWithInvoiceData(string $email): array
    {
        $user = User::query()->create(['name' => 'Hana', 'email' => $email, 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');
        $companyId = $this->postJson('/api/v1/companies', ['legal_name' => 'Harar Traders', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->assertCreated()->json('id');
        $sales = DB::table('accounts')->where(['company_id' => $companyId, 'system_key' => 'sales'])->value('id');
        $vat15 = DB::table('tax_rates')->where(['company_id' => $companyId, 'code' => 'VAT15'])->value('id');
        $customer = (string) \Illuminate\Support\Str::uuid();
        DB::table('contacts')->insert(['id' => $customer, 'company_id' => $companyId, 'name' => 'Sheger Grand Hotel', 'is_customer' => true]);

        $invoice = fn (string $qty) => [
            'contact_id' => $customer, 'number' => 'INV-0001', 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Roasted coffee 1 kg', 'quantity' => $qty, 'unit_price' => '1150', 'account_id' => $sales, 'tax_rate_id' => $vat15]],
        ];

        return [['X-Company-Id' => $companyId], $companyId, $invoice];
    }

    public function test_users_cannot_open_companies_they_do_not_belong_to(): void
    {
        $user = User::query()->create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/invoices', ['X-Company-Id' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->getJson('/api/v1/invoices')->assertStatus(400);
    }
}
