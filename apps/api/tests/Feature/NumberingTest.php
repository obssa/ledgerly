<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, numbering: automatic numbers are taken when an invoice is posted, in order and without gaps, restarting
 * each fiscal year; manual numbering takes the number from the invoice book; a posted number never changes.
 * Roles: PermissionMatrixTest. Database rules: T40.
 */
final class NumberingTest extends TestCase
{
    use RefreshLedgerDatabase;

    private array $h;

    private string $company;

    private string $customer;

    private string $sales;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
    }

    public function test_a_draft_has_no_number_and_posting_takes_the_next_one(): void
    {
        $this->company(['fiscal_year_start_month' => 7]);   // Gregorian, July to June: Nov 2026 is in fiscal year 2027

        $first = $this->draft()->assertCreated()->assertJsonPath('number', null)->json('id');
        $second = $this->draft()->assertCreated()->json('id');
        $this->draft(['number' => 'MY-OWN-1'])->assertUnprocessable()->assertJsonValidationErrors('number');

        $this->approve($second)->assertOk()->assertJsonPath('number', 'INV-2027-0001');
        $this->approve($first)->assertOk()->assertJsonPath('number', 'INV-2027-0002');

        // The next fiscal year starts again at 1.
        $this->approve($this->draft(['issue_date' => '2027-07-01'])->json('id'))->assertOk()->assertJsonPath('number', 'INV-2028-0001');
    }

    public function test_a_posting_that_fails_takes_no_number(): void
    {
        $this->company(['fiscal_year_start_month' => 7]);
        $this->putJson('/api/v1/company/period-lock', ['books_closed_until' => '2026-11-10'], $this->h)->assertOk();

        $this->approve($this->draft(['issue_date' => '2026-11-05'])->json('id'))->assertUnprocessable();
        $this->approve($this->draft(['issue_date' => '2026-11-12'])->json('id'))->assertOk()->assertJsonPath('number', 'INV-2027-0001');
    }

    public function test_the_ethiopian_calendar_names_the_year_in_its_own_calendar(): void
    {
        $this->company(['calendar' => 'ethiopian', 'fiscal_year_start_month' => 11]);   // Hamle to Sene: 2 Nov 2026 is in 2019 E.C.

        $this->approve($this->draft()->json('id'))->assertOk()->assertJsonPath('number', 'INV-2019-0001');
    }

    public function test_the_owner_changes_the_format_and_the_change_is_audited(): void
    {
        $this->company(['fiscal_year_start_month' => 7]);
        $this->putJson('/api/v1/numbering', ['doc_type' => 'invoice', 'mode' => 'automatic', 'prefix' => 'FV/', 'include_year' => false, 'padding' => 6], $this->h)
            ->assertOk()->assertJsonPath('next_number', 'FV/000001');
        $this->putJson('/api/v1/numbering', ['doc_type' => 'invoice', 'mode' => 'automatic', 'prefix' => 'bad prefix!', 'include_year' => false, 'padding' => 6], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('prefix');

        $this->approve($this->draft()->json('id'))->assertOk()->assertJsonPath('number', 'FV/000001');
        $row = collect($this->getJson('/api/v1/numbering', $this->h)->assertOk()->json('data'))->firstWhere('doc_type', 'invoice');
        $this->assertSame('FV/000002', $row['next_number']);

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'action' => 'numbering_changed'])->sole();
        $this->assertEquals(['doc_type' => 'invoice', 'prefix' => 'INV-', 'include_year' => true, 'padding' => 4], json_decode($audit->before, true));
    }

    public function test_manual_numbering_takes_the_number_from_the_invoice_book(): void
    {
        $this->company(['fiscal_year_start_month' => 7]);
        $this->putJson('/api/v1/numbering', ['doc_type' => 'invoice', 'mode' => 'manual', 'prefix' => '', 'include_year' => false, 'padding' => 6], $this->h)->assertOk();

        $this->draft()->assertUnprocessable()->assertJsonValidationErrors('number');
        $id = $this->draft(['number' => 'A-000123'])->assertCreated()->json('id');
        $this->draft(['number' => 'A-000123'])->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->approve($id)->assertOk()->assertJsonPath('number', 'A-000123');
    }

    public function test_a_posted_number_never_changes_and_voiding_keeps_it(): void
    {
        // Not cleared by the tax authority (the Ethiopian connector keeps it queued), so editing and voiding are open.
        config(['einvoicing.force_sandbox' => false]);
        $this->company(['fiscal_year_start_month' => 7]);
        $id = $this->draft()->json('id');
        $this->approve($id)->assertOk();

        $this->putJson("/api/v1/invoices/{$id}", $this->body(['number' => 'INV-9999', 'reason' => 'Renumber']), $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->postJson("/api/v1/invoices/{$id}/void", ['reason' => 'Entered twice'], $this->h)->assertOk();
        $this->assertSame('INV-2027-0001', $this->owner()->table('documents')->where('id', $id)->value('number'));
    }

    /** @param array<string, mixed> $settings */
    private function company(array $settings): void
    {
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', ...$settings])->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
        $this->sales = $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => 'sales'])->value('id');
        $this->customer = (string) Str::uuid();
        $this->owner()->table('contacts')->insert(['id' => $this->customer, 'company_id' => $this->company, 'name' => 'Sheger Grand Hotel', 'is_customer' => true]);
    }

    /** @param array<string, mixed> $over */
    private function body(array $over = []): array
    {
        return [
            'contact_id' => $this->customer, 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $this->sales]],
            ...$over,
        ];
    }

    /** @param array<string, mixed> $over */
    private function draft(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/invoices', $this->body($over), $this->h);
    }

    private function approve(string $id): TestResponse
    {
        return $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h);
    }
}
