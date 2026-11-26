<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 2 reports: AR ageing and the customer statement. Both agree with the receivable account in the ledger.
 * The fixture invoice (1,050.00, 2 Nov 2026, no due date) has 50.00 paid on 3 Nov.
 */
final class ReceivablesReportTest extends TestCase
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

    public function test_the_ageing_buckets_open_invoices_and_each_total_is_the_customers_receivable_balance(): void
    {
        // A payment of 300.00 that is not allocated: the customer's credit.
        $this->postJson('/api/v1/payments-received', ['contact_id' => $this->c['contact'], 'account_id' => $this->c['bank'], 'payment_date' => '2026-11-10',
            'method' => 'cash', 'currency' => 'ETB', 'amount' => '300'], $this->h)->assertCreated();

        $ageing = $this->getJson('/api/v1/reports/ar-ageing?as_of=2026-11-30', $this->h)->assertOk();
        $ageing->assertJsonPath('rows.0.name', 'Abyssinia Coffee customer')
            ->assertJsonPath('rows.0.current', '0.00')
            ->assertJsonPath('rows.0.days_1_30', '1000.00')   // 28 days after 2 Nov
            ->assertJsonPath('rows.0.credits', '-300.00')
            ->assertJsonPath('rows.0.total', '700.00')
            ->assertJsonPath('totals.total', '700.00');

        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $this->h)->json('data'));
        $ar = $tb->firstWhere('account_id', $this->accountId('accounts_receivable'));
        $this->assertSame('700.00', $ar['debit'], 'the ageing total is the receivable account');

        $this->getJson('/api/v1/reports/ar-ageing?as_of=2027-03-01', $this->h)->assertJsonPath('rows.0.over_90', '1000.00');
        // The detail: each open invoice with its days late and bucket; together they are the buckets of the summary.
        $late = $this->getJson('/api/v1/reports/ar-ageing?as_of=2027-03-01', $this->h)->assertOk();
        $documents = collect($late->json('documents'));
        $this->assertGreaterThan(0, $documents->count());
        $this->assertSame($late->json('totals.over_90'), number_format((float) $documents->where('bucket', 'over_90')->sum(fn ($d) => (float) $d['amount']), 2, '.', ''));
        $this->assertTrue($documents->every(fn ($d) => $d['number'] !== null && $d['days_late'] >= 0 && isset($d['issue_date'], $d['amount_due'])));
    }

    public function test_the_ageing_takes_quickbooks_periods_and_the_detail_has_every_field(): void
    {
        // Six periods of 15 days: Current, 1-15, 16-30, 31-45, 46-60, 61-75, Over 75. The invoice is 28 days late.
        $ageing = $this->getJson('/api/v1/reports/ar-ageing?as_of=2026-11-30&interval=15&periods=6', $this->h)->assertOk()
            ->assertJsonPath('interval', 15)->assertJsonPath('periods', 6)
            ->assertJsonPath('buckets', ['current', 'days_1_15', 'days_16_30', 'days_31_45', 'days_46_60', 'days_61_75', 'over_75'])
            ->assertJsonPath('rows.0.days_16_30', '1000.00')->assertJsonPath('totals.days_16_30', '1000.00')->assertJsonPath('totals.total', '1000.00');
        $doc = $ageing->json('documents.0');
        $this->assertSame(['days_16_30', 'invoice', '1050.00', '1000.00'], [$doc['bucket'], $doc['type'], $doc['base_total'], $doc['amount']]);
        $this->assertNotNull($doc['created_by'], 'who made the invoice');
        $this->assertArrayHasKey('posted_by', $doc);
        $this->assertArrayHasKey('exchange_rate', $doc);
        // The standard periods by default, and only whole numbers in range.
        $this->getJson('/api/v1/reports/ar-ageing?as_of=2026-11-30', $this->h)->assertJsonPath('buckets', ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90']);
        $this->getJson('/api/v1/reports/ar-ageing?interval=0', $this->h)->assertUnprocessable()->assertJsonValidationErrors('interval');
        $this->getJson('/api/v1/reports/ar-ageing?periods=13', $this->h)->assertUnprocessable()->assertJsonValidationErrors('periods');
        $this->getJson('/api/v1/reports/ap-ageing?interval=1%3B', $this->h)->assertUnprocessable();
        // At a date before the invoice was issued, it was not owed yet.
        $this->getJson('/api/v1/reports/ar-ageing?as_of=2026-11-01', $this->h)->assertOk()->assertJsonCount(0, 'documents')->assertJsonPath('totals.total', '0.00');
    }

    public function test_the_statement_lists_every_movement_with_a_running_balance(): void
    {
        $this->getJson("/api/v1/customers/{$this->c['contact']}/statement?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('opening', '0.00')
            ->assertJsonPath('lines.0.reference', 'INV-2027-0001')
            ->assertJsonPath('lines.0.debit', '1050.00')
            ->assertJsonPath('lines.0.balance', '1050.00')
            ->assertJsonPath('lines.1.source_type', 'payment')
            ->assertJsonPath('lines.1.credit', '50.00')
            ->assertJsonPath('lines.1.balance', '1000.00')
            ->assertJsonPath('closing', '1000.00')
            // Each line's transaction: its type, what is still open on it, no foreign fields in the company's currency.
            ->assertJsonPath('lines.0.type', 'invoice')->assertJsonPath('lines.0.open_balance', '1000.00')
            ->assertJsonPath('lines.0.foreign_amount', null)->assertJsonPath('lines.0.currency', null)
            ->assertJsonPath('lines.1.type', 'payment_received');
        $line = $this->getJson("/api/v1/customers/{$this->c['contact']}/statement?from=2026-11-01&to=2026-11-30", $this->h)->json('lines.0');
        $this->assertNotNull($line['created_by']);
        $this->assertNotNull($line['entry_id']);

        // A later period starts from what was owed.
        $this->getJson("/api/v1/customers/{$this->c['contact']}/statement?from=2026-12-01&to=2026-12-31", $this->h)
            ->assertJsonPath('opening', '1000.00')->assertJsonCount(0, 'lines')->assertJsonPath('closing', '1000.00');
        $this->getJson("/api/v1/customers/{$this->c['contact']}/statement?from=2026-12-01&to=2026-11-01", $this->h)->assertUnprocessable();
    }

    private function accountId(string $key): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => $key])->value('id');
    }
}
