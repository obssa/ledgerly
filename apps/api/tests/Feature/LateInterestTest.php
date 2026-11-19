<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: interest on late payment (079). At 18% a year, a 10,000.00 invoice 73 days overdue is charged 360.00 on a
 * draft invoice to the same customer, to the income account the company chose, with no tax. The next charge runs from
 * where the last one ended, never the same days twice; voided, its days are charged again. Nothing is charged before
 * the company sets a rate, on an invoice not yet due, or by another company.
 */
final class LateInterestTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_interest_on_late_payment_is_charged_on_a_draft_invoice(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $tz = (string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC');
        $today = now($tz)->format('Y-m-d');
        $day = fn (int $n) => now($tz)->addDays($n)->format('Y-m-d');
        $other = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '4900'])->value('id');

        // Issued 100 days ago, due 73 days ago.
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $day(-100), 'due_date' => $day(-73), 'currency' => 'ETB',
            'lines' => [['description' => 'Green coffee', 'quantity' => '1', 'unit_price' => '10000', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();

        // No rate yet.
        $this->getJson("/api/v1/invoices/{$invoice}/interest", $h)->assertOk()->assertJsonPath('rate', null)->assertJsonPath('amount', '0.00')->assertJsonPath('can.charge', true);
        $this->postJson("/api/v1/invoices/{$invoice}/interest", [], $h)->assertUnprocessable()->assertJsonValidationErrors('rate');

        $version = $this->getJson('/api/v1/company', $h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'late_interest' => ['rate' => '18', 'account_id' => $this->expenseAccount($c['company'])]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('late_interest.account_id');   // not an income account
        $this->putJson('/api/v1/company', ['version' => $version, 'late_interest' => ['rate' => '18', 'account_id' => $other]], $h)->assertOk()
            ->assertJsonPath('late_interest.rate', '18');

        // 73 days at 18% on 10,000.00: 360.00.
        $this->getJson("/api/v1/invoices/{$invoice}/interest", $h)->assertOk()->assertJsonPath('days', 73)->assertJsonPath('amount', '360.00')->assertJsonPath('from', $day(-73));
        $first = $this->postJson("/api/v1/invoices/{$invoice}/interest", [], $h)->assertCreated()
            ->assertJsonPath('status', 'draft')->assertJsonPath('total', '360.00')->assertJsonPath('contact_id', $c['contact'])->json();
        $line = $this->owner()->table('document_lines')->where('document_id', $first['id'])->first();
        $this->assertSame([$other, null], [(string) $line->account_id, $line->tax_rate_id]);

        // Nothing more to charge today; tomorrow's not allowed.
        $this->postJson("/api/v1/invoices/{$invoice}/interest", [], $h)->assertUnprocessable()->assertJsonValidationErrors('through');
        $this->postJson("/api/v1/invoices/{$invoice}/interest", ['through' => $day(1)], $h)->assertUnprocessable()->assertJsonValidationErrors('through');
        $this->getJson("/api/v1/invoices/{$invoice}/interest", $h)->assertOk()->assertJsonPath('from', $today)->assertJsonCount(1, 'charged');

        // Voided, its days are charged again.
        $this->postJson("/api/v1/invoices/{$first['id']}/void", ['reason' => 'Waived this once'], $h)->assertOk();
        $this->postJson("/api/v1/invoices/{$invoice}/interest", ['through' => $day(-36)], $h)->assertCreated()->assertJsonPath('total', '182.47');   // 37 days: 182.4657
        $this->assertContains('late_interest_charged', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $invoice])->pluck('action')->all());

        // An invoice not yet due owes no interest.
        $fresh = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $today, 'due_date' => $day(30), 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting', 'quantity' => '1', 'unit_price' => '500', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$fresh}/approve", [], $h)->assertOk();
        $this->postJson("/api/v1/invoices/{$fresh}/interest", [], $h)->assertUnprocessable()->assertJsonValidationErrors('through');

        // Another company finds none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/invoices/{$invoice}/interest", $hb)->assertNotFound();
        $this->postJson("/api/v1/invoices/{$invoice}/interest", [], $hb)->assertNotFound();
    }

    private function expenseAccount(string $company): string
    {
        return (string) $this->owner()->table('accounts')->where(['company_id' => $company, 'type' => 'expense'])->value('id');
    }
}
