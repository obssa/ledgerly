<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\StatementSent;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * QuickBooks' Create statements: for customers (and suppliers), balance forward, open item or transaction statements
 * for a period, one customer or all with a balance, emailed to each customer. Roles: PermissionMatrixTest. Isolation:
 * TenantIsolationTest. The fixture customer owes 1,050.00 less 50.00 paid less a credit note (CompanyFixtures).
 */
final class ContactStatementsTest extends TestCase
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

    private function statements(string $query, string $side = 'customer'): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/{$side}-statements?{$query}", $this->h)->assertOk();
    }

    public function test_balance_forward_open_item_and_transaction_statements(): void
    {
        $forward = $this->statements('type=balance_forward&from=2026-11-01&to=2026-11-30');
        $mine = collect($forward->json('statements'))->firstWhere('contact.id', $this->c['contact']);
        $this->assertNotNull($mine, 'a customer with a balance gets a statement');
        $this->assertSame('0.00', $mine['opening']);
        $this->assertNotEmpty($mine['lines']);
        $this->assertSame(end($mine['lines'])['balance'], $mine['closing']);
        $this->assertSame($mine['closing'], $mine['ageing']['total'], 'what is owed at the end is its ageing total');

        // Open item: only what is still owed, each with its days overdue.
        $open = collect($this->statements('type=open_item&to=2026-12-31')->json('statements'))->firstWhere('contact.id', $this->c['contact']);
        $this->assertNull($open['opening']);
        $this->assertSame($this->c['invoice'], $open['lines'][0]['document_id']);
        $this->assertGreaterThan(0, $open['lines'][0]['days_overdue']);

        // Transaction statement: the period's transactions, nothing brought forward.
        $tx = collect($this->statements('type=transaction&from=2026-11-03&to=2026-11-30')->json('statements'))->firstWhere('contact.id', $this->c['contact']);
        $this->assertNull($tx['opening']);

        // One customer; one that is not a customer of this company is refused; every customer.
        $this->assertCount(1, $this->statements("contact_id={$this->c['contact']}")->json('statements'));
        $this->getJson('/api/v1/customer-statements?contact_id=' . $this->c['supplier'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->getJson('/api/v1/customer-statements?type=weekly', $this->h)->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertGreaterThanOrEqual(1, count($this->statements('who=all')->json('statements')));

        // Suppliers, from the payable side.
        $this->assertContains($this->c['supplier'], collect($this->statements('to=2026-12-31', 'supplier')->json('statements'))->pluck('contact.id')->all());
    }

    public function test_statements_are_emailed_to_each_customer_with_an_address(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/customer-statements/email', ['contact_ids' => [$this->c['contact']], 'from' => '2026-11-01', 'to' => '2026-11-30'], $this->h)->assertOk()
            ->assertJsonPath('sent', [])->assertJsonPath('skipped.0.reason', 'no_email');
        $this->owner()->table('contacts')->where('id', $this->c['contact'])->update(['email' => 'buyer@example.test']);
        $this->postJson('/api/v1/customer-statements/email', ['contact_ids' => [$this->c['contact']], 'type' => 'open_item', 'to' => '2026-12-31'], $this->h)->assertOk()
            ->assertJsonPath('sent.0.sent_to', 'buyer@example.test');
        Notification::assertSentOnDemand(StatementSent::class, fn (StatementSent $n) => $n->type === 'open_item' && $n->opening === null && $n->lines !== []);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'statement_emailed'])->count());
        // Not twice in a row.
        $this->postJson('/api/v1/customer-statements/email', ['contact_ids' => [$this->c['contact']]], $this->h)->assertOk()->assertJsonPath('skipped.0.reason', 'just_sent');
    }
}
