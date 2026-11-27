<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' Terms list (109): a company starts with the usual terms and adds its own; customers and suppliers have
 * their usual term; an invoice or bill on a term falls due by it, in the company's calendar, unless a date is typed.
 * Roles: PermissionMatrixTest. Isolation: TenantIsolationTest. Database rules: T134. Due-date rule: Unit\PaymentTermsTest.
 */
final class PaymentTermsTest extends TestCase
{
    use RefreshLedgerDatabase;

    private array $h;

    private string $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
    }

    public function test_the_terms_list_starts_with_the_usual_terms_and_takes_the_companys_own(): void
    {
        $this->company([]);
        $names = fn (string $q = '') => collect($this->getJson('/api/v1/payment-terms' . $q, $this->h)->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Due on receipt', 'Net 15', 'Net 30', 'Net 60', 'Due end of month'], $names());

        $term = $this->postJson('/api/v1/payment-terms', ['name' => ' Net 45 ', 'kind' => 'days', 'days' => 45], $this->h)->assertCreated()
            ->assertJsonPath('name', 'Net 45')->assertJsonPath('day_of_month', null)->json('id');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'subject_id' => $term, 'action' => 'created'])->count());
        $this->postJson('/api/v1/payment-terms', ['name' => 'net 45', 'kind' => 'days', 'days' => 40], $this->h)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/payment-terms', ['name' => 'Due 0th', 'kind' => 'day_of_month', 'day_of_month' => 0], $this->h)->assertUnprocessable()->assertJsonValidationErrors('day_of_month');
        $this->postJson('/api/v1/payment-terms', ['name' => 'Net', 'kind' => 'days'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('days');
        $tenth = $this->postJson('/api/v1/payment-terms', ['name' => 'Due on the 10th', 'kind' => 'day_of_month', 'day_of_month' => 10, 'next_month_within' => 5, 'days' => 9], $this->h)
            ->assertCreated()->assertJsonPath('days', null)->assertJsonPath('next_month_within', 5)->json('id');

        // Turned off, not deleted: gone from the list, still read back on its own.
        $this->putJson("/api/v1/payment-terms/{$tenth}", ['name' => 'Due on the 10th', 'kind' => 'day_of_month', 'day_of_month' => 10, 'is_active' => false], $this->h)
            ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('next_month_within', 0);
        $this->assertNotContains('Due on the 10th', $names());
        $this->assertContains('Due on the 10th', $names('?include_inactive=1'));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'subject_id' => $tenth, 'action' => 'updated'])->count());
    }

    public function test_a_customer_and_a_supplier_have_their_usual_term_and_their_days_kept_in_step(): void
    {
        $this->company([]);
        $term = fn (string $name) => $this->owner()->table('payment_terms')->where(['company_id' => $this->company, 'name' => $name])->value('id');

        $this->postJson('/api/v1/customers', ['name' => 'Sheger Grand Hotel', 'payment_terms_id' => $term('Net 60')], $this->h)->assertCreated()
            ->assertJsonPath('payment_terms_id', $term('Net 60'))->assertJsonPath('payment_terms_days', 60);
        $cafe = $this->postJson('/api/v1/customers', ['name' => 'Tomoca Café', 'payment_terms_days' => 15], $this->h)->assertCreated()
            ->assertJsonPath('payment_terms_id', $term('Net 15'))->json('id');   // days alone: the term of those days
        $this->postJson('/api/v1/customers', ['name' => 'Kaldi'], $this->h)->assertCreated()->assertJsonPath('payment_terms_id', $term('Net 30'));
        $this->putJson("/api/v1/customers/{$cafe}", ['payment_terms_id' => $term('Due end of month')], $this->h)->assertOk()
            ->assertJsonPath('payment_terms_id', $term('Due end of month'))->assertJsonPath('payment_terms_days', 30);
        $this->postJson('/api/v1/suppliers', ['name' => 'Ethio Telecom', 'payment_terms_id' => $term('Due on receipt')], $this->h)->assertCreated()
            ->assertJsonPath('payment_terms_days', 0);
        $this->postJson('/api/v1/customers', ['name' => 'Planted', 'payment_terms_id' => (string) Str::uuid()], $this->h)->assertUnprocessable()->assertJsonValidationErrors('payment_terms_id');
    }

    public function test_an_invoice_on_a_term_falls_due_by_it_in_the_companys_calendar_unless_a_date_is_typed(): void
    {
        $this->company([]);
        [$customer, $sales] = $this->customer();
        $term = fn (string $name) => $this->owner()->table('payment_terms')->where(['company_id' => $this->company, 'name' => $name])->value('id');
        $body = fn (array $over) => ['contact_id' => $customer, 'issue_date' => '2026-11-02', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $sales]], ...$over];

        $id = $this->postJson('/api/v1/invoices', $body(['payment_terms_id' => $term('Due end of month')]), $this->h)->assertCreated()
            ->assertJsonPath('due_date', '2026-11-30')->assertJsonPath('payment_terms_id', $term('Due end of month'))->json('id');
        $this->getJson("/api/v1/invoices/{$id}", $this->h)->assertOk()->assertJsonPath('payment_terms.name', 'Due end of month');
        // A date typed stands, and the term stays with it (QuickBooks).
        $this->postJson('/api/v1/invoices', $body(['payment_terms_id' => $term('Net 30'), 'due_date' => '2026-11-20']), $this->h)->assertCreated()
            ->assertJsonPath('due_date', '2026-11-20')->assertJsonPath('payment_terms.name', 'Net 30');

        // An Ethiopian-calendar company's month ends on its own 30th: 2 Nov 2026 is Tikimt 23, due Tikimt 30.
        $this->company(['calendar' => 'ethiopian', 'fiscal_year_start_month' => 11]);
        [$customer, $sales] = $this->customer();
        $this->postJson('/api/v1/invoices', [...$body(['payment_terms_id' => $term('Due end of month')]), 'contact_id' => $customer,
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $sales]]], $this->h)
            ->assertCreated()->assertJsonPath('due_date', '2026-11-09');
    }

    private function company(array $settings): void
    {
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee ' . Str::random(4), 'country_code' => 'ET', 'fiscal_year_start_month' => 7, ...$settings])->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
    }

    /** @return array{0: string, 1: string} a customer and the sales account */
    private function customer(): array
    {
        $customer = (string) Str::uuid();
        $this->owner()->table('contacts')->insert(['id' => $customer, 'company_id' => $this->company, 'name' => 'Sheger Grand Hotel', 'is_customer' => true]);

        return [$customer, $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => 'sales'])->value('id')];
    }
}
