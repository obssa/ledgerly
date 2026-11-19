<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6: journals imported from a spreadsheet. Two entries in one file (an accrual of two lines, a depreciation of
 * two) are checked without posting anything, then posted as manual journals. One unbalanced entry, an unknown account
 * or an account kept by documents stops the whole file and names the row; another company's accounts are not found.
 */
final class JournalImportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_journals_come_from_a_spreadsheet_all_or_none(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $entries = fn () => $this->owner()->table('journal_entries')->where(['company_id' => $c['company'], 'source_type' => 'manual'])->count();
        $csv = "Date,Reference,Memo,Account,Debit,Credit,Description\n"
            . "2026-11-12,ACC-1,Audit fee accrued,6800,15000,,Audit fee for the year\n"
            . "2026-11-12,ACC-1,,2100,,15000,\n"
            . "2026-11-13,DEP-1,Depreciation for November,6800,\"1,250.00\",,\n"
            . "2026-11-13,DEP-1,,1590,,1250.00,\n";
        $before = $entries();

        $this->postJson('/api/v1/imports/journals', ['csv' => "Code,Amount\n6800,1"], $h)->assertUnprocessable()->assertJsonValidationErrors('csv');
        $this->postJson('/api/v1/imports/journals', ['csv' => $csv, 'check' => true], $h)->assertOk()->assertJsonPath('checked', true)
            ->assertJsonPath('entries', 2)->assertJsonPath('errors', [])->assertJsonPath('added', 0);
        $this->assertSame($before, $entries());

        // One bad row stops the whole file, named by its row.
        $unbalanced = str_replace("2026-11-13,DEP-1,,1590,,1250.00,", "2026-11-13,DEP-1,,1100,,1200.00,", $csv);
        $bad = $this->postJson('/api/v1/imports/journals', ['csv' => $unbalanced], $h)->assertOk()->assertJsonPath('added', 0)->json('errors');
        $this->assertNotEmpty($bad);
        $unknown = $this->postJson('/api/v1/imports/journals', ['csv' => str_replace(',2100,', ',9999,', $csv)], $h)->assertOk()->json('errors');
        $this->assertSame(3, $unknown[0]['row']);
        $kept = $this->postJson('/api/v1/imports/journals', ['csv' => str_replace(',2100,', ',1200,', $csv)], $h)->assertOk()->json('errors');   // receivables: kept by documents
        $this->assertNotEmpty($kept);
        $this->postJson('/api/v1/imports/journals', ['csv' => str_replace('2026-11-12', '12/11/2026', $csv)], $h)->assertOk()->assertJsonPath('errors.0.row', 2);
        $this->assertSame($before, $entries());

        $done = $this->postJson('/api/v1/imports/journals', ['csv' => $csv], $h)->assertCreated()->assertJsonPath('added', 2)->json('journals');
        $this->assertSame(['ACC-1', 'DEP-1'], array_column($done, 'reference'));
        $this->assertSame($before + 2, $entries());
        $this->assertSame('Depreciation for November', (string) $this->owner()->table('journal_entries')
            ->where(['company_id' => $c['company'], 'source_type' => 'manual', 'entry_date' => '2026-11-13'])->value('memo'));

        // Another company's codes are its own: company B's file is checked against its own chart.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson('/api/v1/imports/journals', ['csv' => $csv, 'check' => true], ['X-Company-Id' => $b['company']])->assertOk()->assertJsonPath('errors', []);
        $this->assertSame($before + 2, $entries());
    }
}
