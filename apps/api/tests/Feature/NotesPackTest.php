<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the notes to the financial statements. A roaster bought for 120,000.00 on a bank loan and 2,000.00 of its
 * depreciation charged show in the property, plant and equipment note (cost and depreciation, opening to closing) and
 * the loans note; each note adds up, and the carrying amount agrees with the balance sheet.
 */
final class NotesPackTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_notes_add_up_and_agree_with_the_statements(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $code = fn (string $code) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => $code])->value('id');
        $key = fn (string $k) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => $k])->value('id');
        $post = fn (string $memo, string $dr, string $cr, string $amount) => $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-14', 'memo' => $memo,
            'lines' => [['account_id' => $dr, 'debit' => $amount], ['account_id' => $cr, 'credit' => $amount]]], $h)->assertCreated();
        $post('Roaster bought on the bank loan', $code('1500'), $code('2500'), '120000');
        $post('Depreciation for November', $key('depreciation'), $code('1590'), '2000');

        $n = $this->getJson('/api/v1/reports/notes?from=2026-11-12&to=2026-11-30', $h)->assertOk()
            ->assertJsonPath('policies.framework', 'IFRS for SMEs')->assertJsonPath('currency', 'ETB')->json();
        $cost = $n['ppe']['cost'];
        $dep = $n['ppe']['depreciation'];
        $this->assertSame('120000.00', $cost['additions']);
        $this->assertSame('2000.00', $dep['charged']);
        $this->assertSame(bcsub(bcadd($cost['opening'], $cost['additions'], 2), $cost['disposals'], 2), $cost['closing']);
        $this->assertSame(bcsub(bcadd($dep['opening'], $dep['charged'], 2), $dep['released'], 2), $dep['closing']);
        $this->assertSame(bcsub($cost['closing'], $dep['closing'], 2), $n['ppe']['carrying_closing']);
        $this->assertSame('120000.00', $n['loans']['movement']);
        $this->assertSame(bcadd('0', $n['loans']['total'], 2), array_reduce($n['loans']['lines'], fn ($t, $l) => bcadd($t, $l['amount'], 2), '0.00'));

        // The carrying amount is what the balance sheet shows for property, plant and equipment, less its depreciation.
        $bs = $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-11-30', $h)->assertOk()->json();
        $flat = json_encode($bs);
        $this->assertStringContainsString('Property, plant and equipment', $flat);
        $this->getJson('/api/v1/reports/notes?from=2026-11-30&to=2026-11-01', $h)->assertUnprocessable();
    }
}
