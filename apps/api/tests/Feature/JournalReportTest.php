<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: the Journal report, every entry with its lines, agrees with the books and shows only the company's own. */
final class JournalReportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_every_entry_with_its_lines_and_the_quickbooks_fields(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $report = $this->getJson('/api/v1/reports/journal?from=2026-11-01&to=2026-11-30', $h)->assertOk()->assertJsonPath('truncated', false);
        $lines = collect($report->json('lines'));
        $this->assertGreaterThan(0, $lines->count());
        // Every entry balances, and together they are the ledger lines of the period.
        foreach ($lines->groupBy('entry_id') as $entry => $of) {
            $this->assertSame(number_format($of->sum(fn ($l) => (float) $l['debit']), 2, '.', ''), number_format($of->sum(fn ($l) => (float) $l['credit']), 2, '.', ''), "entry {$entry} balances");
        }
        $count = $this->owner()->table('journal_lines')->where('company_id', $c['company'])->whereBetween('entry_date', ['2026-11-01', '2026-11-30'])->count();
        $this->assertSame($count, $lines->count());
        // Each line on its account, with its transaction: the invoice's number and customer.
        $invoice = $lines->firstWhere('type', 'invoice');
        $this->assertSame('INV-2027-0001', $invoice['number']);
        $this->assertSame('Abyssinia Coffee customer', $invoice['contact']);
        $this->assertNotEmpty($invoice['account_code']);
        $this->assertNotEmpty($invoice['account_name']);
        $this->assertNotNull($invoice['created_by']);

        // One kind of entry; all dates; and what is refused.
        $payments = collect($this->getJson('/api/v1/reports/journal?source=payment', $h)->assertOk()->json('lines'));
        $this->assertTrue($payments->isNotEmpty() && $payments->every(fn ($l) => $l['source_type'] === 'payment'));
        $this->assertGreaterThanOrEqual($lines->count(), count($this->getJson('/api/v1/reports/journal', $h)->assertOk()->json('lines')));
        $this->getJson('/api/v1/reports/journal?from=2026-12-01&to=2026-11-01', $h)->assertUnprocessable();
        $this->getJson('/api/v1/reports/journal?source=x%27%3B', $h)->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_another_companys_entries_never_show(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $a = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $userA = $this->lastUser;
        $b = $this->companyWithRecords('other@example.test', 'Harar Traders');
        $this->actingAs($userA, 'sanctum');
        $lines = collect($this->getJson('/api/v1/reports/journal', ['X-Company-Id' => $a['company']])->assertOk()->json('lines'));
        $theirs = $this->owner()->table('journal_entries')->where('company_id', $b['company'])->pluck('id')->all();
        $this->assertNotEmpty($theirs);
        $this->assertSame([], array_values(array_intersect($lines->pluck('entry_id')->unique()->all(), $theirs)), 'none of B\'s entries');
        // And B's company is not A's to read at all.
        $this->getJson('/api/v1/reports/journal', ['X-Company-Id' => $b['company']])->assertForbidden();
    }
}
