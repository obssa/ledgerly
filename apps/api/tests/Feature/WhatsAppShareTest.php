<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ShareController;
use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2 (099): invoices and quotes sent by WhatsApp. A posted invoice gets a link the customer opens without an
 * account (the invoice, what is due, its PDF), and a WhatsApp link with the message written, to the customer's number;
 * a draft is not shared; a voided invoice's link stops working. A draft quote shared is sent, with its answer link. Only
 * the token's hash is kept; each share is audited. A viewer shares nothing; another company cannot share these.
 */
final class WhatsAppShareTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_invoices_and_quotes_go_out_by_whatsapp_as_links(): void
    {
        config(['einvoicing.force_sandbox' => false, 'einvoicing.countries' => [], 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->owner()->table('contacts')->where('id', $c['contact'])->update(['phone' => '+251 911 22 33 44']);
        $tokenOf = fn (string $url) => (string) preg_replace('/^.*[?&]token=/', '', $url);

        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'lines' => [['description' => 'Roasted beans', 'quantity' => '2', 'unit_price' => '500', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/share", [], $h)->assertUnprocessable()->assertJsonValidationErrors('invoice');   // a draft
        ['number' => $number, 'amount_due' => $due] = $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk()->json();
        $shared = $this->postJson("/api/v1/invoices/{$invoice}/share", [], $h)->assertOk()->assertJsonPath('to', '251911223344')->json();
        $this->assertStringStartsWith('https://wa.me/251911223344?text=', $shared['whatsapp_url']);
        $this->assertStringContainsString($number, rawurldecode($shared['whatsapp_url']));
        $this->assertStringContainsString($shared['url'], $shared['message']);
        $token = $tokenOf($shared['url']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->owner()->table('invoice_links')->where('document_id', $invoice)->value('token_hash'), 'only the hash is kept');
        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'invoice_shared', 'subject_id' => $invoice])->sole();
        $this->assertStringNotContainsString($token, (string) $audit->after);
        $this->assertNull(ShareController::international('0911 22 33 44'));   // a local number: the sender picks the chat

        // Signed out: the link shows that invoice and its PDF, and only with the right token.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/invoice?token=' . str_repeat('0', 64))->assertNotFound();
        $this->getJson('/api/v1/public/invoice?token=../../etc')->assertUnprocessable();
        $this->getJson("/api/v1/public/invoice?token={$token}")->assertOk()->assertJsonPath('company.name', 'Abyssinia Coffee')
            ->assertJsonPath('invoice.number', $number)->assertJsonPath('invoice.amount_due', $due)->assertJsonPath('invoice.paid', false)->assertJsonCount(1, 'invoice.lines');
        $pdf = $this->get("/api/v1/public/invoice/pdf?token={$token}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Voided, its link stops working.
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Raised in error'], $h)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/invoice?token={$token}")->assertNotFound();

        // A draft quote shared by WhatsApp is sent, with the link to answer it.
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $quote = $this->postJson("/api/v1/quotes/{$c['quote']}/share", [], $h)->assertOk()->assertJsonPath('status', 'sent')->json();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/quote?token=' . $tokenOf($quote['url']))->assertOk()->assertJsonPath('can_answer', true);

        // A viewer shares nothing; another company cannot reach these.
        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $this->postJson("/api/v1/invoices/{$c['invoice']}/share", [], $h)->assertForbidden();
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/invoices/{$c['invoice']}/share", [], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
