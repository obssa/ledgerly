<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tax\TaxAuditFile;
use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 7: the filing calendar from the country pack's due dates, and the tax audit file. */
final class TaxCalendarTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_calendar_shows_each_month_with_its_due_date_and_where_it_stands(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-22 09:00:00', 'UTC'));
        $cal = $this->getJson('/api/v1/tax/calendar', $this->h)->assertOk()->assertJsonPath('today', '2026-12-22');
        $vat = collect($cal->json('kinds.vat.periods'))->keyBy('period_start');
        // Ethiopia: due 30 days after the month. December is still open; November is due on 30 Dec, within ten days.
        $this->assertSame(['open', '2027-01-30'], [$vat['2026-12-01']['status'], $vat['2026-12-01']['due_on']]);
        $this->assertSame(['due_soon', '2026-12-30'], [$vat['2026-11-01']['status'], $vat['2026-11-01']['due_on']]);
        $this->assertSame('overdue', $vat['2026-10-01']['status']);
        $this->assertSame(['filed', $this->c['tax_return']], [$vat['2026-09-01']['status'], $vat['2026-09-01']['return']['id']]);   // the fixture's September return
        $this->assertSame('2026-12-01', $cal->json('kinds.vat.periods.0.period_start'), 'newest first');
        $this->assertCount(13, $vat, 'the past twelve months and the current one');
        $this->assertNotEmpty($cal->json('kinds.vat.note'));
        $this->assertArrayHasKey('withholding', $cal->json('kinds'));
        // Overdue: VAT Dec 2025 to Oct 2026 but September (10), withholding Dec 2025 to Oct 2026 (11).
        $this->assertSame([21, 2], [$cal->json('overdue'), $cal->json('due_soon')]);
    }

    public function test_kenya_is_due_on_the_twentieth_and_has_no_monthly_withholding_return(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-05 09:00:00', 'UTC'));
        $company = $this->postJson('/api/v1/companies', ['legal_name' => 'Nairobi Roasters', 'country_code' => 'KE', 'fiscal_year_start_month' => 1])->assertCreated()->json('id');
        $cal = $this->getJson('/api/v1/tax/calendar', ['X-Company-Id' => $company])->assertOk();
        $vat = collect($cal->json('kinds.vat.periods'))->keyBy('period_start');
        $this->assertSame(['due', '2026-12-20'], [$vat['2026-11-01']['status'], $vat['2026-11-01']['due_on']]);
        $this->assertSame(['2027-01-20'], [$vat['2026-12-01']['due_on']]);
        $this->assertArrayNotHasKey('withholding', $cal->json('kinds'));
    }

    public function test_the_audit_file_has_the_documents_and_the_ledger_of_the_period(): void
    {
        $docs = $this->getJson('/api/v1/tax/audit-file?part=documents&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()
            ->assertJsonPath('filename', 'documents-2026-11-01-to-2026-11-30.csv')->assertJsonPath('truncated', false);
        $lines = explode("\n", trim((string) $docs->json('csv')));
        $this->assertSame('type,number,date,status,customer_or_supplier,tin,currency,fx_rate,subtotal,tax,total,journal_entry', $lines[0]);
        $invoice = $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('number');
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_starts_with($l, "invoice,{$invoice},")));
        $this->assertEmpty(array_filter($lines, fn ($l) => str_contains($l, ',draft,')), 'drafts are not in the books');

        $ledger = $this->getJson('/api/v1/tax/audit-file?part=ledger&from=2026-11-01&to=2026-11-30', $this->h)->assertOk();
        $this->assertStringStartsWith('entry,date,source,memo,account_code,account,description,contact,debit,credit,entry_status', (string) $ledger->json('csv'));
        $this->assertGreaterThan(5, $ledger->json('rows'));
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'tax_audit_file_exported'])->count());
    }

    public function test_a_cell_a_spreadsheet_would_run_is_escaped(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", TaxAuditFile::safe('=HYPERLINK("http://x")'));
        $this->assertSame("'+SUM(A1)", TaxAuditFile::safe('+SUM(A1)'));
        $this->assertSame("'@cmd", TaxAuditFile::safe('@cmd'));
        $this->assertSame("'-x", TaxAuditFile::safe('-x'));
        $this->assertSame('-10.50', TaxAuditFile::safe('-10.50'));
        $this->assertSame('Abebe Bikila', TaxAuditFile::safe('Abebe Bikila'));
        $this->assertSame('', TaxAuditFile::safe(null));
    }
}
