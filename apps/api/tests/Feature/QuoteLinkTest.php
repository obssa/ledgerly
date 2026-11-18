<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\InvoiceSent;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2: a quote accepted online (065). The emailed quote carries a link; the customer, signed out, sees that quote
 * only and accepts it once, by name; a link of a cancelled quote, a used link and a wrong token lead nowhere.
 */
final class QuoteLinkTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_customer_sees_the_quote_through_its_link_and_accepts_it_once(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->owner()->table('contacts')->where('id', $c['contact'])->update(['email' => 'buyer@example.test']);
        $quote = fn () => $this->postJson('/api/v1/quotes', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-02', 'due_date' => '2027-01-31', 'currency' => 'ETB',
            'lines' => [['description' => 'Roasted beans', 'quantity' => '10', 'unit_price' => '500', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $linkOf = function (): string {
            $link = null;
            Notification::assertSentOnDemand(InvoiceSent::class, function (InvoiceSent $n) use (&$link) {
                $link = $n->link;

                return true;
            });
            $this->assertNotNull($link);

            return (string) preg_replace('/^.*[?&]token=/', '', (string) $link);
        };

        Notification::fake();
        $first = $quote();
        $this->postJson("/api/v1/quotes/{$first}/email", [], $h)->assertOk()->assertJsonPath('status', 'sent');
        $token = $linkOf();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->owner()->table('quote_links')->where('document_id', $first)->value('token_hash'), 'only the hash is kept');

        // Signed out: the link shows that quote, and only with the right token.
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->getJson('/api/v1/public/quote?token=' . str_repeat('0', 64))->assertNotFound();
        $this->getJson('/api/v1/public/quote?token=../../etc')->assertUnprocessable();
        $this->getJson("/api/v1/public/quote?token={$token}")->assertOk()->assertJsonPath('company.name', 'Abyssinia Coffee')
            ->assertJsonPath('quote.total', '5000.00')->assertJsonPath('quote.valid_until', '2027-01-31')->assertJsonPath('can_answer', true)
            ->assertJsonMissingPath('quote.id')->assertJsonMissingPath('company.id');

        $this->postJson('/api/v1/public/quote/answer', ['token' => $token, 'answer' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/public/quote/answer', ['token' => $token, 'answer' => 'accepted', 'name' => 'Abebe Kebede'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/public/quote/answer', ['token' => $token, 'answer' => 'declined', 'name' => 'Someone else'])->assertUnprocessable();
        $this->assertSame('accepted', $this->owner()->table('documents')->where('id', $first)->value('status'));
        $audit = $this->owner()->table('audit_log')->where(['subject_id' => $first, 'action' => 'quote_accepted_online'])->sole();
        $this->assertSame(['system', 'Accepted online by Abebe Kebede'], [$audit->actor_type, $audit->reason]);
        $this->assertNull($audit->user_id);

        // A quote the company cancelled after sending: its link answers nothing.
        $this->actingAs(\App\Models\User::query()->findOrFail($c['user']), 'sanctum');
        Notification::fake();
        $second = $quote();
        $this->postJson("/api/v1/quotes/{$second}/email", [], $h)->assertOk();
        $other = $linkOf();
        $this->postJson("/api/v1/quotes/{$second}/cancel", ['note' => 'Prices changed'], $h)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->getJson("/api/v1/public/quote?token={$other}")->assertOk()->assertJsonPath('can_answer', false)->assertJsonPath('quote.status', 'void');
        $this->postJson('/api/v1/public/quote/answer', ['token' => $other, 'answer' => 'accepted', 'name' => 'Abebe Kebede'])->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->assertSame('void', $this->owner()->table('documents')->where('id', $second)->value('status'));
    }
}
