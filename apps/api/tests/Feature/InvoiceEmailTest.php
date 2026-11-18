<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\InvoiceSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 2: a posted invoice sent to the customer by email, with its lines and totals. */
final class InvoiceEmailTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_posted_invoice_goes_to_the_customers_email_with_its_lines_once_in_a_while(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        Notification::fake();

        $this->postJson("/api/v1/invoices/{$c['draft']}/email", [], $h)->assertUnprocessable()->assertJsonValidationErrors('invoice');   // a draft
        $this->postJson("/api/v1/invoices/{$c['invoice']}/email", [], $h)->assertUnprocessable()->assertJsonValidationErrors('invoice');   // no email yet
        $this->owner()->table('contacts')->where('id', $c['contact'])->update(['email' => 'buyer@example.test']);

        $this->postJson("/api/v1/invoices/{$c['invoice']}/email", ['note' => 'Thank you for your order.'], $h)->assertOk()->assertJsonPath('sent_to', 'buyer@example.test');
        Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $n, array $channels, AnonymousNotifiable $to) {
            return array_key_exists('buyer@example.test', (array) $to->routes['mail'])
                && $n->company === 'Abyssinia Coffee' && $n->note === 'Thank you for your order.'
                && count($n->lines) === 1 && $n->lines[0]['description'] === 'Coffee' && $n->total === '1,050.00';
        });
        $this->postJson("/api/v1/invoices/{$c['invoice']}/email", [], $h)->assertUnprocessable();   // not again straight away
        Notification::assertSentOnDemandTimes(InvoiceSent::class, 1);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $c['invoice'], 'action' => 'invoice_emailed'])->count());

        // The message reads as an invoice: its number, the lines and the totals.
        $sent = null;
        Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $n) use (&$sent) {
            $sent = $n;

            return true;
        });
        $text = implode("\n", (array) $sent->toMail(new AnonymousNotifiable())->introLines);
        $this->assertStringContainsString('Coffee: 2 × 500.00', $text);
        $this->assertStringContainsString('total ETB 1,050.00', $text);
    }
}
