<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the transaction reports (TransactionReport). Each lists what its name says, with the fields QuickBooks
 * offers (type, number, name, due date, amount, open balance, foreign amount and open balance, currency, created and
 * posted by), from the company's own books only, to a role that may see those documents.
 */
final class TransactionReportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private function report(string $company, array $q): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/reports/transactions?' . http_build_query($q), ['X-Company-Id' => $company]);
    }

    public function test_each_report_lists_what_its_name_says_with_the_quickbooks_fields(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $owner = $this->lastUser;
        $h = ['X-Company-Id' => $a['company']];

        // A posted invoice in dollars, so the foreign columns have something to say.
        $usd = $this->postJson('/api/v1/invoices', ['contact_id' => $a['contact'], 'issue_date' => '2026-11-04', 'currency' => 'USD',
            'lines' => [['description' => 'Export lot', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $a['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$usd}/approve", [], $h)->assertOk();
        $usdDoc = $this->owner()->table('documents')->where('id', $usd)->first();
        $posted = $this->owner()->table('documents')->where('id', $a['invoice'])->first();

        // Invoice list: every invoice, drafts too, in base currency, with who made it.
        $invoices = collect($this->report($a['company'], ['report' => 'invoices'])->assertOk()->assertJsonPath('currency', 'ETB')->json('rows'));
        $this->assertSame(['invoice'], $invoices->pluck('type')->unique()->values()->all());
        $row = $invoices->firstWhere('id', $a['invoice']);
        $this->assertSame([$posted->number, 'Abyssinia Coffee customer', '2026-11-02'], [$row['number'], $row['name'], $row['date']]);
        $this->assertSame((string) $posted->total, $row['amount']);
        $this->assertSame((string) $posted->amount_due, $row['open_balance']);
        $this->assertSame('Abyssinia Coffee', $row['created_by']);
        $this->assertNull($row['currency'], 'a transaction in the base currency has no foreign columns');
        $this->assertNull($row['foreign_amount']);
        $this->assertContains($a['draft'], $invoices->pluck('id')->all());

        // The dollar invoice: base amounts at its rate, foreign amounts as written, its currency and rate.
        $dollar = $invoices->firstWhere('id', $usd);
        $this->assertSame('USD', $dollar['currency']);
        $this->assertSame('200.00', $dollar['foreign_amount']);
        $this->assertSame('200.00', $dollar['foreign_open_balance']);
        $this->assertSame(number_format(round(200 * (float) $usdDoc->fx_rate, 2), 2, '.', ''), $dollar['amount']);

        // Open invoices: only what is still owed; a payment shows as applied, the open balance what is left.
        $open = collect($this->report($a['company'], ['report' => 'open_invoices'])->assertOk()->json('rows'));
        $this->assertNotContains($a['draft'], $open->pluck('id')->all());
        $this->assertSame((string) $posted->amount_due, $open->firstWhere('id', $a['invoice'])['open_balance']);
        $this->assertTrue($open->every(fn ($r) => (float) $r['open_balance'] > 0));

        // Payments received: the 50.00 payment, applied to the invoice.
        $payments = collect($this->report($a['company'], ['report' => 'payments_received'])->assertOk()->json('rows'));
        $payment = $payments->firstWhere('id', $a['payment']);
        $this->assertSame(['payment_received', '50.00'], [$payment['type'], $payment['amount']]);
        $this->assertStringContainsString((string) $posted->number, (string) $payment['applied_to']);

        // Bill list and unpaid bills: the supplier's side.
        $bills = collect($this->report($a['company'], ['report' => 'bills'])->assertOk()->json('rows'));
        $this->assertContains($a['posted_bill'], $bills->pluck('id')->all());
        $this->assertSame(['bill'], $bills->pluck('type')->unique()->values()->all());
        $this->assertContains($a['posted_bill'], collect($this->report($a['company'], ['report' => 'unpaid_bills'])->json('rows'))->pluck('id')->all());

        // Transaction list by date: every posted entry, typed, in date order, with who posted it.
        $all = collect($this->report($a['company'], ['report' => 'transactions'])->assertOk()->json('rows'));
        $this->assertSame($all->pluck('date')->sort()->values()->all(), $all->pluck('date')->values()->all());
        foreach (['invoice', 'payment_received', 'bill', 'manual'] as $type) {
            $this->assertContains($type, $all->pluck('type')->all(), "the transaction list has a {$type}");
        }
        $this->assertSame('Abyssinia Coffee', $all->firstWhere('type', 'invoice')['posted_by']);
        // The period narrows it.
        $this->assertSame([], $this->report($a['company'], ['report' => 'transactions', 'from' => '2020-01-01', 'to' => '2020-12-31'])->json('rows'));

        // Filters: a customer, overdue only; and nothing of company B, whatever is asked.
        $this->assertSame([$a['contact']], array_values(array_unique(array_map(fn ($r) => $this->owner()->table('documents')->where('id', $r['id'])->value('contact_id'),
            $this->report($a['company'], ['report' => 'invoices', 'contact_id' => $a['contact']])->json('rows')))));
        $this->report($a['company'], ['report' => 'invoices', 'contact_id' => $b['contact']])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $theirs = $this->owner()->table('documents')->where('company_id', $b['company'])->pluck('id')->all();
        foreach (['transactions', 'invoices', 'bills', 'payments_received'] as $report) {
            $this->assertSame([], array_intersect($theirs, collect($this->report($a['company'], ['report' => $report])->json('rows'))->pluck('id')->all()), $report);
        }

        // Nonsense is refused, not run.
        $this->report($a['company'], ['report' => 'invoices; DROP TABLE documents'])->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->report($a['company'], ['report' => 'invoices', 'status' => "open' OR 1=1"])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertNotNull($owner);
    }

    public function test_a_role_sees_only_the_reports_of_what_it_may_see(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $clerk = $this->createUser('Sales clerk', 'clerk@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $a['company'], 'user_id' => $clerk->getKey(), 'role' => 'custom', 'status' => 'active',
            'permissions' => json_encode(['reports.view', 'invoices.view'])]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($clerk, 'sanctum');

        $this->report($a['company'], ['report' => 'invoices'])->assertOk();
        $this->report($a['company'], ['report' => 'open_invoices'])->assertOk();
        $this->report($a['company'], ['report' => 'bills'])->assertForbidden();
        $this->report($a['company'], ['report' => 'payments_received'])->assertForbidden();
        $this->report($a['company'], ['report' => 'transactions'])->assertForbidden();
    }

    public function test_a_transaction_report_is_saved_with_its_customize_choices(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $a['company']];
        $saved = $this->postJson('/api/v1/saved-reports', ['name' => 'Who owes us', 'report' => 'open-invoices', 'period' => 'all',
            'options' => ['cols' => 'name,number,due_date,days_past_due,open_balance,foreign_open_balance,currency', 'group' => 'name', 'sort' => 'open_balance', 'dir' => 'desc']], $h)
            ->assertCreated()->assertJsonPath('options.cols', 'name,number,due_date,days_past_due,open_balance,foreign_open_balance,currency')
            ->assertJsonPath('options.group', 'name')->assertJsonPath('covers', ['from' => null, 'to' => null]);
        $this->assertNotNull($saved->json('id'));

        // Only known columns and values; a status only on the lists that have one.
        $this->postJson('/api/v1/saved-reports', ['name' => 'Bad', 'report' => 'open-invoices', 'period' => 'all', 'options' => ['cols' => 'name,password']], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('options.cols');
        $this->postJson('/api/v1/saved-reports', ['name' => 'Bad', 'report' => 'invoice-list', 'period' => 'last_month', 'options' => ['sort' => 'amount; DROP TABLE documents']], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('options.sort');
        $this->postJson('/api/v1/saved-reports', ['name' => 'Overdue', 'report' => 'invoice-list', 'period' => 'last_month', 'options' => ['status' => 'overdue']], $h)
            ->assertCreated()->assertJsonPath('options.status', 'overdue');
    }
}
