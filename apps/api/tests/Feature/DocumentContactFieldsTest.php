<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\InvoiceSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' invoice header and footer (112): billing and shipping addresses as printed, the email it goes to with Cc
 * and Bcc, how it ships, and a message the customer's statement shows. Database rules: T137.
 */
final class DocumentContactFieldsTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    private function invoice(array $over): array
    {
        return ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', ...$over,
            'lines' => [['description' => 'Roasted coffee', 'quantity' => '1', 'unit_price' => '500', 'account_id' => $this->c['sales']]]];
    }

    public function test_an_invoice_keeps_who_it_goes_to_where_it_ships_and_its_statement_message(): void
    {
        $fields = ['billing_address' => "Bole Road\nAddis Ababa", 'shipping_address' => "Warehouse 4\nAdama", 'email_to' => 'ap@hotel.example; manager@hotel.example',
            'email_cc' => 'sales@abyssinia.example', 'email_bcc' => 'books@abyssinia.example', 'ship_via' => 'Ethiopian Cargo', 'ship_date' => '2026-11-13',
            'tracking_number' => 'ET-778', 'message_on_statement' => 'Coffee for the November event'];
        $id = $this->postJson('/api/v1/invoices', $this->invoice([...$fields, 'tracking_number' => ' ET-778 ', 'ship_via' => '']), $this->h)->assertCreated()
            ->assertJsonPath('billing_address', "Bole Road\nAddis Ababa")->assertJsonPath('tracking_number', 'ET-778')->assertJsonPath('ship_via', null)
            ->assertJsonPath('ship_date', '2026-11-13')->json('id');
        $this->postJson('/api/v1/invoices', $this->invoice(['email_cc' => 'sales@abyssinia.example, not-an-email']), $this->h)->assertUnprocessable()->assertJsonValidationErrors('email_cc');
        $this->postJson('/api/v1/invoices', $this->invoice(['ship_via' => str_repeat('x', 61)]), $this->h)->assertUnprocessable()->assertJsonValidationErrors('ship_via');

        $version = $this->getJson("/api/v1/invoices/{$id}", $this->h)->json('lock_version');
        $this->putJson("/api/v1/invoices/{$id}", [...$this->invoice($fields), 'lock_version' => $version], $this->h)->assertOk()->assertJsonPath('ship_via', 'Ethiopian Cargo');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();

        // Emailed to its own To, with its Cc and Bcc.
        Notification::fake();
        $this->postJson("/api/v1/invoices/{$id}/email", [], $this->h)->assertOk()->assertJsonPath('sent_to', 'ap@hotel.example, manager@hotel.example');
        Notification::assertSentOnDemand(InvoiceSent::class, fn (InvoiceSent $n, array $channels, AnonymousNotifiable $to) => array_keys((array) $to->routes['mail']) === ['ap@hotel.example', 'manager@hotel.example']
            && $n->cc === ['sales@abyssinia.example'] && $n->bcc === ['books@abyssinia.example']);

        // The customer's statement shows its message.
        $lines = collect($this->getJson("/api/v1/customers/{$this->c['contact']}/statement?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()->json('lines'));
        $this->assertSame('Coffee for the November event', $lines->firstWhere('document_id', $id)['memo']);

        // The PDF prints Bill to and Ship to.
        $pdf = $this->get("/api/v1/invoices/{$id}/pdf", $this->h)->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }
}
