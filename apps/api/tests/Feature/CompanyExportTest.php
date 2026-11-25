<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Export\CompanyExport;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;
use ZipArchive;

/** Module 11: an owner downloads everything the company keeps, as CSV files in a ZIP, and nothing of another company. */
final class CompanyExportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
    }

    public function test_an_owner_downloads_every_table_of_the_company_and_nothing_of_another(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        RateLimiter::clear('company-export:' . $a['user']);

        $response = $this->get('/api/v1/company/export', ['X-Company-Id' => $a['company']])->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('evenbooks-abyssinia-coffee-', (string) $response->headers->get('Content-Disposition'));
        $files = $this->unzip($response->baseResponse);

        // Every company table is in it, but not Evenbooks' own machinery or link hashes.
        foreach (array_keys(app(CompanyExport::class)->tables()) as $table) {
            if ($table !== 'memberships') {
                $this->assertArrayHasKey("{$table}.csv", $files, "{$table} is missing from the export");
            }
        }
        foreach (['company.csv', 'people.csv', 'accounts.csv', 'documents.csv', 'journal_entries.csv', 'journal_lines.csv', 'payments.csv', 'audit_log.csv', 'README.txt'] as $name) {
            $this->assertArrayHasKey($name, $files);
        }
        foreach (CompanyExport::LEFT_OUT as $table) {
            $this->assertArrayNotHasKey("{$table}.csv", $files);
        }

        $this->assertStringContainsString($a['company'], $files['company.csv']);
        foreach ($files as $name => $contents) {
            if ($name !== 'README.txt') {
                $this->assertMatchesRegularExpression('/^\x{FEFF}[a-z_0-9]+(,[a-z_0-9]+)*\r\n/u', $contents, "{$name} has no heading");
            }
        }
        $this->assertStringContainsString('a@example.test', $files['people.csv']);
        $this->assertStringStartsWith("\u{FEFF}id,", $files['journal_lines.csv']);
        $this->assertSame(
            $this->owner()->table('journal_lines')->where('company_id', $a['company'])->count() + 1,
            substr_count($files['journal_lines.csv'], "\r\n"),
            'one line per journal line, and the heading'
        );

        $everything = implode("\n", $files);
        foreach (['company', 'user', 'organisation', 'contact', 'invoice', 'payment', 'sales', 'bank'] as $key) {
            $this->assertStringNotContainsString($b[$key], $everything, "company B's {$key} is in company A's export");
        }
        $this->assertStringNotContainsString('Blue Nile Traders', $everything);
        $this->assertStringNotContainsString('b@example.test', $everything);

        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $a['company'], 'action' => 'company_exported'])->count());
    }

    public function test_cells_a_spreadsheet_would_run_are_neutralised_and_downloads_are_limited(): void
    {
        $this->assertSame("'=SUM(A1:A9)", CompanyExport::cell('=SUM(A1:A9)'));
        $this->assertSame("'@cmd", CompanyExport::cell('@cmd'));
        $this->assertSame("'+251 911", CompanyExport::cell('+251 911'));
        $this->assertSame('-1250.50', CompanyExport::cell('-1250.50'));
        $this->assertSame('true', CompanyExport::cell(true));
        $this->assertSame('', CompanyExport::cell(null));

        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        RateLimiter::clear('company-export:' . $a['user']);
        $h = ['X-Company-Id' => $a['company']];
        for ($i = 0; $i < 3; $i++) {
            $this->get('/api/v1/company/export', $h)->assertOk();
        }
        $this->getJson('/api/v1/company/export', $h)->assertStatus(429)->assertJsonPath('type', 'TooManyExports');
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['company_id' => $a['company'], 'action' => 'company_exported'])->count());
    }

    /** @return array<string, string> file name => contents */
    private function unzip(mixed $response): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true, 'not a readable ZIP');
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $this->assertSame(count($files), count(array_unique(array_keys($files))));

        return $files;
    }
}
