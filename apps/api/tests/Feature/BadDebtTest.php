<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: bad debts (078). Of a 1,000.00 invoice, 600.00 is written off: one entry Dr Bad debts, Cr Accounts
 * receivable, and the invoice owes 400.00. Never more than it owes, always with a reason. The customer pays after all:
 * the write-off is reversed and the invoice owes 1,000.00 again. Written off in full, it owes nothing. Another company
 * finds none of it.
 */
final class BadDebtTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_bad_debt_is_written_off_and_reversed(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $system = fn (string $key) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $key])->value('id');
        $balance = fn (string $key) => (string) $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'account_id' => $system($key)])
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS b')->value('b');
        $due = fn (string $id) => [(string) $this->owner()->table('documents')->where('id', $id)->value('amount_due'), (string) $this->owner()->table('documents')->where('id', $id)->value('status')];

        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting service', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['reason' => 'Before posting'], $h)->assertUnprocessable();   // a draft
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        [$ar, $bad] = [$balance('accounts_receivable'), $balance('bad_debt')];

        $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['amount' => '600'], $h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['amount' => '1200', 'reason' => 'Customer closed'], $h)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $w = $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['amount' => '600', 'reason' => 'Customer closed the shop'], $h)
            ->assertCreated()->assertJsonPath('amount', '600.00')->json();
        $this->assertSame(['400.00', 'part_paid'], $due($invoice));
        $this->assertSame([bcsub($ar, '600.00', 2), bcadd($bad, '600.00', 2)], [$balance('accounts_receivable'), $balance('bad_debt')]);
        $this->assertSame('bad_debt', $this->owner()->table('journal_entries')->where('id', $w['entry_id'])->value('source_type'));
        $this->getJson("/api/v1/invoices/{$invoice}/write-offs", $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.write_off', true);

        // While it stands, the invoice is not edited.
        $this->putJson("/api/v1/invoices/{$invoice}", ['contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB', 'reason' => 'Fix the price',
            'lines' => [['description' => 'Roasting service', 'quantity' => '1', 'unit_price' => '900', 'account_id' => $c['sales']]]], $h)->assertUnprocessable();

        // The customer paid after all: reversed, the invoice owes it all again.
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs/reverse", ['reason' => 'Paid in the end'], $h)->assertOk()->assertJsonPath('reversed_on', $today);
        $this->assertSame(['1000.00', 'posted'], $due($invoice));
        $this->assertSame([$ar, $bad], [$balance('accounts_receivable'), $balance('bad_debt')]);
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs/reverse", [], $h)->assertUnprocessable()->assertJsonValidationErrors('write_off_id');

        // Written off in full: it owes nothing.
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['reason' => 'Gone for good'], $h)->assertCreated()->assertJsonPath('amount', '1000.00');
        $this->assertSame(['0.00', 'paid'], $due($invoice));

        $actions = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $invoice])->pluck('action')->all();
        $this->assertContains('bad_debt_written_off', $actions);
        $this->assertContains('bad_debt_reversed', $actions);

        // Another company finds none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/invoices/{$invoice}/write-offs", $hb)->assertNotFound();
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs", ['reason' => 'Not ours'], $hb)->assertNotFound();
        $this->postJson("/api/v1/invoices/{$invoice}/write-offs/reverse", [], $hb)->assertNotFound();
    }
}
