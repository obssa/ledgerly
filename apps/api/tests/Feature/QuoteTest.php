<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\InvoiceSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: quotes and sales orders (056). A quote is sent (numbered), revised with its earlier version kept, accepted
 * and converted to a sales order, which is confirmed and converted to a draft invoice; quotes past their date expire
 * and are extended; nothing posts, and another company never reaches them.
 */
final class QuoteTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private function quote(array $c, array $over = []): array
    {
        return [...['contact_id' => $c['contact'], 'issue_date' => '2026-11-02', 'due_date' => '2026-11-30', 'currency' => 'ETB', 'notes' => 'Delivery in Addis',
            'lines' => [['description' => 'Roasted beans', 'quantity' => '10', 'unit_price' => '500', 'account_id' => $c['sales'], 'tax_rate_id' => $c['tax_rate']]]], ...$over];
    }

    public function test_a_quote_becomes_a_sales_order_then_a_draft_invoice_and_keeps_what_the_customer_saw(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();
        $before = $entries();

        $this->postJson('/api/v1/quotes', $this->quote($c, ['contact_id' => $b['contact']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $quote = $this->postJson('/api/v1/quotes', $this->quote($c), $h)->assertCreated()
            ->assertJsonPath('type', 'quote')->assertJsonPath('status', 'draft')->assertJsonPath('number', null)
            ->assertJsonPath('total', '5250.00')->assertJsonPath('amount_due', '0.00')->assertJsonPath('can.send', true)->json();
        $id = $quote['id'];

        // Sent: it takes its number. Revised while the customer decides: the version they saw is kept, the number stays.
        $sent = $this->postJson("/api/v1/quotes/{$id}/send", [], $h)->assertOk()->assertJsonPath('status', 'sent')->json();
        $this->assertMatchesRegularExpression('/^QT-\d{4}-0001$/', $sent['number']);
        $this->putJson("/api/v1/quotes/{$id}", $this->quote($c, ['lock_version' => $quote['lock_version']]), $h)->assertStatus(409);
        $this->putJson("/api/v1/quotes/{$id}", $this->quote($c, ['lock_version' => $sent['lock_version'], 'reason' => 'Customer asked for 12 bags',
            'lines' => [['description' => 'Roasted beans', 'quantity' => '12', 'unit_price' => '500', 'account_id' => $c['sales'], 'tax_rate_id' => $c['tax_rate']]]]), $h)
            ->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('number', $sent['number'])->assertJsonPath('total', '6300.00')->assertJsonPath('versions', 1);
        $history = $this->getJson("/api/v1/quotes/{$id}/history", $h)->assertOk();
        $this->assertSame('5250.00', $history->json('versions.0.snapshot.total'));
        $this->assertSame('Customer asked for 12 bags', $history->json('versions.0.reason'));

        // Each kind stays on its own routes; another company does not find it at all.
        $this->getJson("/api/v1/sales-orders/{$id}", $h)->assertNotFound();
        $this->getJson("/api/v1/invoices/{$id}", $h)->assertNotFound();
        $this->getJson("/api/v1/quotes/{$c['draft']}", $h)->assertNotFound();
        $hb = ['X-Company-Id' => $b['company']];
        $this->actingAs(\App\Models\User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/quotes/{$id}", $hb)->assertNotFound();
        $this->postJson("/api/v1/quotes/{$id}/accept", [], $hb)->assertNotFound();
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/quotes', $hb)->assertOk()->json('data'), 'id'));
        $this->actingAs(\App\Models\User::query()->findOrFail($c['user']), 'sanctum');

        // Accepted and converted to a sales order: same customer, lines and prices; the quote is converted, once.
        $this->postJson("/api/v1/quotes/{$id}/accept", [], $h)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('can.convert', true);
        $this->postJson("/api/v1/quotes/{$id}/convert", ['to' => 'bill'], $h)->assertUnprocessable()->assertJsonValidationErrors('to');
        $order = $this->postJson("/api/v1/quotes/{$id}/convert", ['to' => 'sales_order'], $h)->assertCreated()->assertJsonPath('type', 'sales_order')->json('id');
        $this->getJson("/api/v1/quotes/{$id}", $h)->assertJsonPath('status', 'converted')->assertJsonPath('made.id', $order)->assertJsonPath('can.convert', false);
        $this->postJson("/api/v1/quotes/{$id}/convert", ['to' => 'invoice'], $h)->assertUnprocessable();
        $this->getJson("/api/v1/sales-orders/{$order}", $h)->assertOk()->assertJsonPath('source.id', $id)->assertJsonPath('total', '6300.00')
            ->assertJsonPath('lines.0.quantity', '12.00')->assertJsonPath('contact.id', $c['contact'])->assertJsonPath('notes', 'Delivery in Addis');

        // Confirmed (numbered SO-), then invoiced: a draft invoice made from the order, for the customer to be billed.
        $this->postJson("/api/v1/sales-orders/{$order}/send", [], $h)->assertNotFound();   // orders are confirmed, not sent
        $confirmed = $this->postJson("/api/v1/sales-orders/{$order}/accept", [], $h)->assertOk()->assertJsonPath('status', 'accepted')->json('number');
        $this->assertMatchesRegularExpression('/^SO-\d{4}-0001$/', $confirmed);
        $invoice = $this->postJson("/api/v1/sales-orders/{$order}/convert", ['to' => 'invoice'], $h)->assertCreated()->assertJsonPath('type', 'invoice')
            ->assertJsonPath('status', 'draft')->json('id');
        $this->getJson("/api/v1/invoices/{$invoice}", $h)->assertOk()->assertJsonPath('source_document_id', $order)->assertJsonPath('total', '6300.00')
            ->assertJsonPath('amount_due', '6300.00');
        $this->getJson("/api/v1/sales-orders/{$order}", $h)->assertJsonPath('status', 'converted')->assertJsonPath('made.id', $invoice);
        $this->assertSame($before, $entries(), 'quotes and orders post nothing');

        $audit = $this->owner()->table('audit_log')->where('company_id', $c['company'])->whereIn('subject_id', [$id, $order])->pluck('action')->all();
        foreach (['quote_saved', 'quote_sent', 'quote_revised', 'quote_accepted', 'quote_converted', 'sales_order_saved', 'sales_order_confirmed', 'sales_order_converted'] as $action) {
            $this->assertContains($action, $audit);
        }
    }

    public function test_quotes_are_declined_cancelled_expired_extended_and_emailed(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $new = fn (array $over = []) => $this->postJson('/api/v1/quotes', $this->quote($c, $over), $h)->assertCreated()->json('id');

        $declined = $new();
        $this->postJson("/api/v1/quotes/{$declined}/decline", [], $h)->assertUnprocessable();   // not sent yet
        $this->postJson("/api/v1/quotes/{$declined}/send", [], $h)->assertOk();
        $this->postJson("/api/v1/quotes/{$declined}/decline", ['note' => 'Too expensive'], $h)->assertOk()->assertJsonPath('status', 'declined')
            ->assertJsonPath('can.edit', false)->assertJsonPath('can.convert', false);
        $this->putJson("/api/v1/quotes/{$declined}", $this->quote($c), $h)->assertUnprocessable();

        $cancelled = $new();
        $this->postJson("/api/v1/quotes/{$cancelled}/cancel", [], $h)->assertOk()->assertJsonPath('status', 'void');

        // A sent quote past its date expires overnight; an expired quote is offered again to a later date.
        $old = $new(['due_date' => '2026-11-10']);
        $this->postJson("/api/v1/quotes/{$old}/send", [], $h)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
        $this->artisan('ledgerly:expire-quotes')->assertSuccessful();
        $this->getJson("/api/v1/quotes/{$old}", $h)->assertJsonPath('status', 'sent');   // still valid on its last day
        $this->travelTo(Carbon::parse('2026-11-11 12:00'));
        $this->artisan('ledgerly:expire-quotes')->assertSuccessful();
        $this->getJson("/api/v1/quotes/{$old}", $h)->assertJsonPath('status', 'expired')->assertJsonPath('can.extend', true);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $old, 'action' => 'quote_expired', 'actor_type' => 'system'])->count());
        $this->postJson("/api/v1/quotes/{$old}/accept", [], $h)->assertUnprocessable();
        $this->postJson("/api/v1/quotes/{$old}/extend", ['valid_until' => '2026-11-01'], $h)->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->postJson("/api/v1/quotes/{$old}/extend", ['valid_until' => '2026-11-25'], $h)->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('due_date', '2026-11-25');

        // Emailed: a draft is sent (numbered) on the way; not without an address, and not twice in a row.
        Notification::fake();
        $mailed = $new();
        $this->postJson("/api/v1/quotes/{$mailed}/email", [], $h)->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->owner()->table('contacts')->where('id', $c['contact'])->update(['email' => 'buyer@example.test']);
        $this->postJson("/api/v1/quotes/{$mailed}/email", [], $h)->assertOk()->assertJsonPath('sent_to', 'buyer@example.test')->assertJsonPath('status', 'sent');
        $this->postJson("/api/v1/quotes/{$mailed}/email", [], $h)->assertUnprocessable();
        $sent = null;
        Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $n) use (&$sent) {
            $sent = $n;

            return true;
        });
        $mail = $sent->toMail(new \Illuminate\Notifications\AnonymousNotifiable());
        $this->assertStringStartsWith('Quote QT-', $mail->subject);
        $this->assertStringContainsString('valid until 30/11/2026', implode("\n", (array) $mail->introLines));
        $this->assertStringNotContainsString('Still due', implode("\n", (array) $mail->introLines));
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $mailed, 'action' => 'quote_emailed'])->count());
    }
}
