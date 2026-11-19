<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: a statement line split over several accounts (077). The fuel station's 115.00 was 100.00 of fuel and a
 * 15.00 card fee: one entry, the bank credited the whole line, each part debited on its own account. The parts add up
 * to the line, never to an account kept by its own documents; undoing reverses the one entry; another company cannot
 * split the line.
 */
final class SplitStatementLineTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_line_is_split_over_several_accounts_in_one_entry(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // statement line: fuel 115.00 out of the bank
        $h = ['X-Company-Id' => $c['company']];
        $charges = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'bank_charges'])->value('id');
        $receivable = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $line = $c['statement_fuel'];
        $split = fn (array $parts, array $hh = []) => $this->postJson("/api/v1/statement-lines/{$line}/split", ['parts' => $parts], $hh ?: $h);

        $split([['account_id' => $c['rent'], 'amount' => '115']])->assertUnprocessable()->assertJsonValidationErrors('parts');
        $split([['account_id' => $c['rent'], 'amount' => '100'], ['account_id' => $charges, 'amount' => '10']])->assertUnprocessable()->assertJsonValidationErrors('parts');
        $split([['account_id' => $c['rent'], 'amount' => '100'], ['account_id' => $receivable, 'amount' => '15']])->assertUnprocessable()->assertJsonValidationErrors('parts.1.account_id');

        // Another company cannot split it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $split([['account_id' => $b['rent'], 'amount' => '100'], ['account_id' => $b['rent'], 'amount' => '15']], ['X-Company-Id' => $b['company']])->assertNotFound();
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $split([['account_id' => $c['rent'], 'amount' => '100', 'memo' => 'Fuel'], ['account_id' => $charges, 'amount' => '15', 'memo' => 'Card fee']])
            ->assertOk()->assertJsonPath('status', 'categorised');
        $journalLine = $this->owner()->table('bank_transactions')->where('id', $line)->value('journal_line_id');
        $entry = $this->owner()->table('journal_lines')->where('id', $journalLine)->value('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get(['account_id', 'debit', 'credit', 'description']);
        $this->assertCount(3, $lines);
        $this->assertSame('115.00', (string) $lines->firstWhere('account_id', $c['bank'])->credit);
        $this->assertSame('100.00', (string) $lines->firstWhere('account_id', $c['rent'])->debit);
        $this->assertSame(['15.00', 'Card fee'], [(string) $lines->firstWhere('account_id', $charges)->debit, $lines->firstWhere('account_id', $charges)->description]);
        $audit = json_decode((string) $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $line, 'action' => 'statement_line_categorised'])->value('after'), true);
        $this->assertCount(2, $audit['parts']);

        // Undone: the one entry is reversed, the line is back to review.
        $this->postJson("/api/v1/statement-lines/{$line}/undo", ['reason' => 'Split wrongly'], $h)->assertOk()->assertJsonPath('status', 'imported');
        $this->assertSame('reversed', $this->owner()->table('journal_entries')->where('id', $entry)->value('status'));
    }
}
