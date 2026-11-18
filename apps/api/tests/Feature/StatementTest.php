<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: statements. The fixtures import the bank's statement for 7 Nov: the 115.00 fuel expense already in the books
 * and a 2.50 SMS charge that is not. Each line is matched, categorised or excluded, and every step can be undone.
 */
final class StatementTest extends TestCase
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

    public function test_a_statement_is_imported_once_and_its_lines_wait_for_review(): void
    {
        $lines = $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/statement-lines", $this->h)->assertOk()
            ->assertJsonPath('counts.imported', 2)->assertJsonPath('can.review', true);
        $this->assertSame(['-115.00', '-2.50'], collect($lines->json('data'))->pluck('amount')->sort()->values()->all());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['bank'], 'action' => 'statement_imported'])->count());

        // The same statement again, with one new line: only the new line comes in.
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/statements", ['file_name' => 'cbe-nov.csv', 'csv' => "Date,Description,Reference,Debit,Credit,Balance\n"
            . "07/11/2026,Fuel station R-7781,FT26311,115.00,,\n07/11/2026,SMS alert charge,,2.50,,\n09/11/2026,Interest,,,4.10,\n"], $this->h)
            ->assertCreated()->assertJsonPath('imported', 1)->assertJsonPath('skipped', 2)->assertJsonPath('from', '2026-11-07')->assertJsonPath('to', '2026-11-09');

        // Not into undeposited funds; nothing half-imported from a statement with a bad row.
        $undeposited = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'undeposited_funds'])->value('id');
        $this->postJson("/api/v1/bank-accounts/{$undeposited}/statements", ['csv' => "Date,Description,Amount\n05/11/2026,x,1\n"], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('csv');
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/statements", ['csv' => "Date,Description,Amount\n10/11/2026,Good,1\n10/13/2026,Bad,1\n"], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('csv');
        $this->assertSame(3, $this->owner()->table('bank_transactions')->where('company_id', $this->c['company'])->count());
    }

    public function test_a_line_is_matched_to_the_entry_already_in_the_books(): void
    {
        $lines = collect($this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/statement-lines", $this->h)->json('data'))->keyBy('id');
        $fuel = $lines[$this->c['statement_fuel']];
        $this->assertCount(1, $fuel['suggestions']);
        $this->assertSame($this->c['bank_line'], $fuel['suggestions'][0]['journal_line_id']);
        $this->assertSame([], $lines[$this->c['statement_line']]['suggestions'], 'nothing in the books is 2.50');

        // Not to an entry for another amount, nor to a line on another account.
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/match", ['journal_line_id' => $this->c['bank_line']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('journal_line_id');
        $rentLine = $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->c['rent']])->value('id');
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/match", ['journal_line_id' => $rentLine], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('journal_line_id');

        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/match", ['journal_line_id' => $this->c['bank_line']], $this->h)->assertOk()
            ->assertJsonPath('status', 'matched')->assertJsonPath('entry.source_type', 'expense');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['statement_fuel'], 'action' => 'statement_line_matched'])->count());
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/match", ['journal_line_id' => $this->c['bank_line']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // The matched expense cannot be voided out from under the statement; undo the match first.
        $this->postJson("/api/v1/expenses/{$this->c['expense']}/void", ['reason' => 'Entered twice'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/undo", ['reason' => 'Wrong receipt'], $this->h)->assertOk()
            ->assertJsonPath('status', 'imported')->assertJsonPath('entry', null);
        $this->postJson("/api/v1/expenses/{$this->c['expense']}/void", ['reason' => 'Entered twice'], $this->h)->assertOk();
        $after = collect($this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/statement-lines", $this->h)->json('data'))->keyBy('id');
        $this->assertSame([], $after[$this->c['statement_fuel']]['suggestions'], 'a voided expense is no longer a match');
    }

    public function test_a_line_is_categorised_with_a_new_entry_and_undone_by_reversing_it(): void
    {
        $charges = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'bank_charges'])->value('id');
        $receivable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/categorise", ['account_id' => $receivable], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/categorise", ['account_id' => $this->c['bank']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $done = $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/categorise", ['account_id' => $charges, 'memo' => 'SMS alerts, November'], $this->h)
            ->assertOk()->assertJsonPath('status', 'categorised')->assertJsonPath('entry.source_type', 'bank_transaction')->assertJsonPath('entry.date', '2026-11-07');
        $entry = $done->json('entry.id');
        $this->assertEquals(['2.50', '0.00'], array_values((array) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $charges])->first(['debit', 'credit'])));
        $this->assertEquals(['0.00', '2.50'], array_values((array) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $this->c['bank']])->first(['debit', 'credit'])));

        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/undo", ['reason' => 'Belongs to the wallet'], $this->h)->assertOk()->assertJsonPath('status', 'imported');
        $this->assertSame('reversed', $this->owner()->table('journal_entries')->where('id', $entry)->value('status'));
        $this->assertSame(1, $this->owner()->table('journal_entries')->where(['source_type' => 'reversal', 'source_id' => $entry])->count());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['statement_line'], 'action' => 'statement_line_reopened'])->count());
    }

    public function test_a_dollar_accounts_line_is_categorised_in_dollars_at_the_days_rate(): void
    {
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'CBE USD account', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => '2026-11-07', 'rate' => '154.50'], $this->h)->assertCreated();
        $this->postJson("/api/v1/bank-accounts/{$usd}/statements", ['csv' => "Date,Description,Amount\n07/11/2026,SWIFT charge,-12.00\n30/10/2026,Card fee,-3.00\n"], $this->h)->assertCreated();
        $lines = collect($this->getJson("/api/v1/bank-accounts/{$usd}/statement-lines", $this->h)->json('data'))->keyBy('description');
        $charges = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'bank_charges'])->value('id');

        // 12.00 dollars of charges at 154.50: 1,854.00 birr, an entry in dollars.
        $done = $this->postJson("/api/v1/statement-lines/{$lines['SWIFT charge']['id']}/categorise", ['account_id' => $charges], $this->h)->assertOk()
            ->assertJsonPath('status', 'categorised');
        $entry = $this->owner()->table('journal_entries')->where('id', $done->json('entry.id'))->first();
        $this->assertSame(['USD', '154.500000'], [$entry->currency, (string) $entry->fx_rate]);
        $this->assertSame('1854.00', (string) $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry->id, 'account_id' => $charges])->value('debit'));
        $this->getJson("/api/v1/bank-accounts/{$usd}", $this->h)->assertOk()->assertJsonPath('balance', '-12.00');

        // No rate known on 30 October and none given: the person is asked for one; given, it is used. A birr bank account cannot take a dollar line.
        $this->postJson("/api/v1/statement-lines/{$lines['Card fee']['id']}/categorise", ['account_id' => $charges], $this->h)->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        $this->postJson("/api/v1/statement-lines/{$lines['Card fee']['id']}/categorise", ['account_id' => $this->c['bank'], 'fx_rate' => '155'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/statement-lines/{$lines['Card fee']['id']}/categorise", ['account_id' => $charges, 'fx_rate' => '155'], $this->h)->assertOk();
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'statement_line_categorised'])
            ->where('after->base', '1854.00')->count());
    }

    public function test_a_line_is_excluded_with_a_reason_and_brought_back(): void
    {
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/exclude", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/exclude", ['reason' => 'Refunded by the bank the same day'], $this->h)->assertOk()
            ->assertJsonPath('status', 'excluded')->assertJsonPath('note', 'Refunded by the bank the same day');
        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/statement-lines?status=excluded", $this->h)->assertOk()
            ->assertJsonPath('counts.excluded', 1)->assertJsonPath('data.0.id', $this->c['statement_line']);
        $this->assertSame('Refunded by the bank the same day', $this->owner()->table('audit_log')
            ->where(['subject_id' => $this->c['statement_line'], 'action' => 'statement_line_excluded'])->value('reason'));

        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/undo", [], $this->h)->assertOk()->assertJsonPath('status', 'imported')->assertJsonPath('note', null);
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/undo", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_viewers_read_the_statement_and_cannot_review_it(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/statement-lines", $this->h)->assertOk()->assertJsonPath('can.review', false);
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_line']}/exclude", ['reason' => 'Not mine'], $this->h)->assertForbidden();
    }
}
