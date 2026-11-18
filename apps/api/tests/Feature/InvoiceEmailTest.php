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

        // The invoice itself goes with it, as a PDF file.
        $mail = $sent->toMail(new AnonymousNotifiable());
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertStringStartsWith('%PDF-', $mail->rawAttachments[0]['data']);
        $this->assertSame('application/pdf', $mail->rawAttachments[0]['options']['mime']);
        $this->assertStringEndsWith('.pdf', $mail->rawAttachments[0]['name']);
    }

    public function test_a_posted_invoice_downloads_as_a_pdf_built_from_its_own_escaped_records(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->owner()->table('contacts')->where('id', $c['contact'])->update(['name' => '<img src="http://evil.example/x.png"> Kaldi & Sons']);

        $this->get("/api/v1/invoices/{$c['draft']}/pdf", [...$h, 'Accept' => 'application/json'])->assertUnprocessable();   // a draft has none
        $file = $this->get("/api/v1/invoices/{$c['invoice']}/pdf", $h)->assertOk();
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', (string) $file->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $file->getContent());

        // The page is the company's records, escaped: a name with markup is text, never an image fetched from elsewhere.
        $html = \App\Domain\Tenancy\Support\DatabaseTenantContext::run($c['user'], $c['company'], fn () => app(\App\Domain\Sales\InvoicePdf::class)->html(
            \App\Domain\Tenancy\Models\Company::query()->findOrFail($c['company']), \App\Domain\Sales\Models\Document::query()->findOrFail($c['invoice'])));
        $this->assertStringContainsString('&lt;img src=&quot;http://evil.example/x.png&quot;&gt; Kaldi &amp; Sons', $html);
        $this->assertStringNotContainsString('<img', $html);

        $this->actingAs(\App\Models\User::query()->findOrFail($b['user']), 'sanctum');
        $this->get("/api/v1/invoices/{$c['invoice']}/pdf", ['X-Company-Id' => $b['company'], 'Accept' => 'application/json'])->assertNotFound();
    }
}
