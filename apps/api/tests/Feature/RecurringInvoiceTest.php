<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: recurring invoices (077). "Repeat this invoice" makes a template from an invoice; each time it comes due it
 * makes an ordinary invoice dated that day, due by the customer's terms, posted (or left as a draft), naming the
 * template, and moves on to the next date. A paused template makes nothing; one past its end stops; the hourly run
 * makes what is due in every company. A credit note is not repeated. Another company never sees a template.
 */
final class RecurringInvoiceTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_repeated_invoice_is_made_on_its_dates(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $made = fn (string $t) => $this->owner()->table('documents')->where(['company_id' => $c['company'], 'recurring_invoice_id' => $t])->orderBy('issue_date')->get();

        $this->postJson("/api/v1/invoices/{$c['credit_note']}/repeat", ['frequency' => 'monthly', 'next_on' => $today], $h)->assertUnprocessable();
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['frequency' => 'monthly', 'next_on' => $yesterday], $h)->assertUnprocessable()->assertJsonValidationErrors('next_on');
        $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['frequency' => 'daily', 'next_on' => $today], $h)->assertUnprocessable()->assertJsonValidationErrors('frequency');

        // Monthly from today, posted: made now, then next month.
        $t = $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['name' => 'Coffee retainer', 'frequency' => 'monthly', 'next_on' => $today, 'post' => true], $h)
            ->assertCreated()->assertJsonPath('is_active', true)->assertJsonPath('post', true)->json();
        $source = $this->getJson("/api/v1/invoices/{$c['invoice']}", $h)->json();
        $this->assertCount(count($source['lines']), $t['lines']);
        $run = $this->postJson("/api/v1/recurring-invoices/{$t['id']}/make-due", [], $h)->assertOk()->assertJsonPath('error', null)->assertJsonCount(1, 'made')->json();
        $invoice = $made($t['id'])->first();
        $this->assertSame(['posted', $today, $source['total']], [$invoice->status, substr((string) $invoice->issue_date, 0, 10), (string) $invoice->total]);
        $this->assertSame(\App\Domain\Sales\RecurringInvoices::advance($today, 'monthly'), $run['next_on']);
        $this->assertSame(1, $run['made_count']);
        $this->postJson("/api/v1/recurring-invoices/{$t['id']}/make-due", [], $h)->assertOk()->assertJsonCount(0, 'made');

        // Paused, it makes nothing; resumed from today, it makes again as the person who resumed it.
        $this->postJson("/api/v1/recurring-invoices/{$t['id']}/pause", [], $h)->assertOk()->assertJsonPath('is_active', false);
        $this->postJson("/api/v1/recurring-invoices/{$t['id']}/resume", ['next_on' => $today], $h)->assertOk()->assertJsonPath('is_active', true)->assertJsonPath('next_on', $today);

        // The hourly run makes it (and only what is due).
        Artisan::call('ledgerly:make-recurring-invoices');
        $this->assertCount(2, $made($t['id']));

        // Weekly drafts ending today: one draft, then it stops.
        $w = $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['frequency' => 'weekly', 'next_on' => $today, 'ends_on' => $today], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/recurring-invoices/{$w}/make-due", [], $h)->assertOk()->assertJsonCount(1, 'made')->assertJsonPath('is_active', false);
        $this->assertSame('draft', $made($w)->first()->status);
        $this->postJson("/api/v1/recurring-invoices/{$w}/resume", [], $h)->assertUnprocessable()->assertJsonValidationErrors('next_on');

        $this->assertContains($t['id'], array_column($this->getJson('/api/v1/recurring-invoices', $h)->assertOk()->json('data'), 'id'));
        $actions = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $t['id']])->pluck('action')->all();
        foreach (['recurring_invoice_created', 'recurring_invoice_made', 'recurring_invoice_paused', 'recurring_invoice_resumed'] as $a) {
            $this->assertContains($a, $actions);
        }

        // Another company sees none of it.
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/recurring-invoices/{$t['id']}", $hb)->assertNotFound();
        $this->postJson("/api/v1/recurring-invoices/{$t['id']}/pause", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['frequency' => 'monthly', 'next_on' => $today], $hb)->assertNotFound();
        $this->assertNotContains($t['id'], array_column($this->getJson('/api/v1/recurring-invoices', $hb)->assertOk()->json('data'), 'id'));
    }
}
