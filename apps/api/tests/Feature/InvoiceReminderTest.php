<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvoiceReminder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 2, collections: payment reminders for invoices still owed, by hand and on a schedule (048). */
final class InvoiceReminderTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        Notification::fake();
    }

    public function test_a_reminder_by_hand_goes_to_the_customer_once_a_day_and_is_recorded(): void
    {
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $a['company']];

        $this->postJson("/api/v1/invoices/{$a['invoice']}/remind", [], $h)->assertUnprocessable()->assertJsonValidationErrors('invoice'); // no email yet
        $this->owner()->table('contacts')->where('id', $a['contact'])->update(['email' => 'buyer@example.test']);
        $this->postJson("/api/v1/invoices/{$a['draft']}/remind", [], $h)->assertUnprocessable();   // a draft is not owed

        $this->postJson("/api/v1/invoices/{$a['invoice']}/remind", [], $h)->assertOk()
            ->assertJsonPath('reminders.0.kind', 'manual')->assertJsonPath('reminders.0.sent_to', 'buyer@example.test')->assertJsonPath('can.remind', true);
        Notification::assertSentOnDemand(InvoiceReminder::class, fn (InvoiceReminder $n, array $channels, AnonymousNotifiable $to) =>
            array_key_exists('buyer@example.test', (array) $to->routes['mail']) && $n->company === 'Abyssinia Coffee' && $n->amountDue === '1,000.00');
        $this->postJson("/api/v1/invoices/{$a['invoice']}/remind", [], $h)->assertUnprocessable();   // once in 24 hours
        Notification::assertSentOnDemandTimes(InvoiceReminder::class, 1);

        $this->assertSame(1, $this->owner()->table('invoice_reminders')->where('document_id', $a['invoice'])->count());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $a['invoice'], 'action' => 'invoice_reminder_sent'])->count());
    }

    public function test_scheduled_reminders_go_once_per_step_and_only_where_turned_on(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $a['company']];
        $this->owner()->table('contacts')->whereIn('id', [$a['contact'], $b['contact']])->update(['email' => 'buyer@example.test']);

        $version = $this->getJson('/api/v1/company', $h)->assertOk()->assertJsonPath('reminders.enabled', false)->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'reminders' => ['enabled' => true, 'days' => [30, 7]]], $h)->assertOk()
            ->assertJsonPath('reminders', ['enabled' => true, 'days' => [7, 30]]);
        $this->putJson('/api/v1/company', ['version' => $this->getJson('/api/v1/company', $h)->json('version'), 'reminders' => ['enabled' => true, 'days' => [0]]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('reminders.days.0');

        // An invoice of its own with a due date (the fixture's has none, so no reminder could ever fall due for it).
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $a['contact'], 'issue_date' => '2026-11-02', 'due_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '400', 'account_id' => $a['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        // Company B (reminders off) has an invoice owed on the same dates.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company']];
        $bInvoice = $this->postJson('/api/v1/invoices', ['contact_id' => $b['contact'], 'issue_date' => '2026-11-02', 'due_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '400', 'account_id' => $b['sales']]]], $hb)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$bInvoice}/approve", [], $hb)->assertOk();
        Notification::fake();   // forget the e-invoice and other mail of the setup
        $a['invoice'] = $invoice;
        $due = Carbon::parse('2026-11-12');
        $this->travelTo($due->copy()->addDays(6)->setTime(12, 0));
        $this->artisan('ledgerly:send-invoice-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo($due->copy()->addDays(7)->setTime(12, 0));
        $this->artisan('ledgerly:send-invoice-reminders')->assertSuccessful();
        $this->artisan('ledgerly:send-invoice-reminders')->assertSuccessful();   // a step goes once
        $this->assertSame([7], $this->owner()->table('invoice_reminders')->where('document_id', $a['invoice'])->pluck('step')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(0, $this->owner()->table('invoice_reminders')->where('company_id', $b['company'])->count(), 'company B has reminders off');

        $this->travelTo($due->copy()->addDays(31)->setTime(12, 0));
        $this->artisan('ledgerly:send-invoice-reminders')->assertSuccessful();
        $this->assertSame([7, 30], $this->owner()->table('invoice_reminders')->where('document_id', $a['invoice'])->orderBy('step')->pluck('step')->map(fn ($s) => (int) $s)->all());
        Notification::assertSentOnDemandTimes(InvoiceReminder::class, 2);
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['subject_id' => $a['invoice'], 'action' => 'invoice_reminder_sent'])->count());
    }
}
