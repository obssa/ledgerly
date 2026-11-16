<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: reconciliation. The fixtures leave a reconciliation of the bank to 8 Nov in progress (ending balance 1.00,
 * so not yet agreed). The bank's entries up to then: the opening cash, a payment received, the advance paid, the fuel
 * expense and the advance given back.
 */
final class ReconciliationTest extends TestCase
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

    private function bookBalanceOn(string $date): string
    {
        return $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/register?from=2026-01-01&to={$date}", $this->h)->assertOk()->json('closing');
    }

    public function test_a_statement_is_reconciled_at_a_zero_difference_and_locks_the_period(): void
    {
        $index = $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", $this->h)->assertOk()
            ->assertJsonPath('draft_id', $this->c['reconciliation'])->assertJsonPath('next_opening', '0.00')->assertJsonPath('reconciled_through', null);
        $r = $this->c['reconciliation'];
        $work = $this->getJson("/api/v1/reconciliations/{$r}", $this->h)->assertOk()->assertJsonPath('status', 'draft');
        $lines = collect($work->json('lines'));
        $this->assertGreaterThanOrEqual(4, $lines->count());
        $this->assertTrue($lines->every(fn ($l) => $l['date'] <= '2026-11-08'));

        // The fuel line on the statement is matched first: it is reconciled with the entry.
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/match", ['journal_line_id' => $this->c['bank_line']], $this->h)->assertOk();

        // Every entry ticked but one: the difference is that entry.
        $balance = $this->bookBalanceOn('2026-11-08');
        $refund = $lines->firstWhere('amount', '10.00');
        $this->assertNotNull($refund);
        $this->putJson("/api/v1/reconciliations/{$r}", ['ending_balance' => $balance,
            'cleared' => $lines->pluck('journal_line_id')->reject(fn ($id) => $id === $refund['journal_line_id'])->values()->all()], $this->h)->assertOk()
            ->assertJsonPath('difference', '10.00')->assertJsonPath('outstanding_total', '10.00');
        $this->postJson("/api/v1/reconciliations/{$r}/complete", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('difference');

        $done = $this->putJson("/api/v1/reconciliations/{$r}", ['cleared' => $lines->pluck('journal_line_id')->all()], $this->h)->assertOk()
            ->assertJsonPath('difference', '0.00')->assertJsonPath('book_balance', $balance);
        $this->postJson("/api/v1/reconciliations/{$r}/complete", [], $this->h)->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('ending', $balance)->assertJsonCount($lines->count(), 'cleared')->assertJsonCount(0, 'outstanding');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['bank'], 'action' => 'reconciliation_completed'])->count());
        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", $this->h)->assertOk()
            ->assertJsonPath('reconciled_through', '2026-11-08')->assertJsonPath('next_opening', $balance)->assertJsonPath('draft_id', null);
        $this->assertSame('reconciled', $this->owner()->table('bank_transactions')->where('id', $this->c['statement_fuel'])->value('status'));

        // Locked: nothing new on the bank on or before 8 Nov, no reversal of what was reconciled, no undo of its statement match.
        $entry = fn (string $date) => $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => 'Late cash', 'lines' => [
            ['account_id' => $this->c['bank'], 'debit' => '5'], ['account_id' => $this->c['equity'], 'credit' => '5']]], $this->h);
        $this->assertStringContainsString('reconciled through 2026-11-08', (string) $entry('2026-11-05')->assertUnprocessable()->json('message'));
        $this->assertStringContainsString('reconcil', (string) $this->postJson("/api/v1/expenses/{$this->c['expense']}/void", ['reason' => 'Entered twice'], $this->h)
            ->assertUnprocessable()->json('message'));
        $this->postJson("/api/v1/statement-lines/{$this->c['statement_fuel']}/undo", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');
        $entry('2026-11-09')->assertCreated();
        $this->putJson("/api/v1/reconciliations/{$r}", ['ending_balance' => '2'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');

        // The next statement starts from this one and ends after it.
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", ['statement_date' => '2026-11-08', 'ending_balance' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('statement_date');
        $next = $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", ['statement_date' => '2026-11-10', 'ending_balance' => $balance], $this->h)
            ->assertCreated()->assertJsonPath('opening_balance', $balance)->assertJsonPath('difference', '0.00');
        $this->assertCount(1, $next->json('lines'), 'only the 9 Nov entry is left to tick');

        // Undo: not while the next one is in progress, never without a reason.
        $this->postJson("/api/v1/reconciliations/{$r}/undo", ['reason' => 'Wrong statement'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/reconciliations/{$next->json('id')}", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/v1/reconciliations/{$next->json('id')}", ['reason' => 'Started too early'], $this->h)->assertOk();
        $this->postJson("/api/v1/reconciliations/{$r}/undo", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/reconciliations/{$r}/undo", ['reason' => 'The bank sent a corrected statement'], $this->h)->assertOk()
            ->assertJsonPath('status', 'undone')->assertJsonPath('undo_reason', 'The bank sent a corrected statement');
        $this->assertSame('matched', $this->owner()->table('bank_transactions')->where('id', $this->c['statement_fuel'])->value('status'));
        $this->assertSame(0, $this->owner()->table('reconciliation_lines')->where('reconciliation_id', $r)->count());
        $entry('2026-11-05')->assertCreated();   // the period is open again
        $this->assertSame('The bank sent a corrected statement', $this->owner()->table('audit_log')
            ->where(['subject_id' => $this->c['bank'], 'action' => 'reconciliation_undone'])->value('reason'));
    }

    public function test_what_can_be_ticked_and_started(): void
    {
        $r = $this->c['reconciliation'];
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", ['statement_date' => '2026-11-09', 'ending_balance' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('statement_date');   // one in progress already
        $this->putJson("/api/v1/reconciliations/{$r}", ['statement_date' => '2026-11-05'], $this->h)->assertOk();
        $late = $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->c['bank']])->where('entry_date', '>', '2026-11-05')->value('id');
        $this->putJson("/api/v1/reconciliations/{$r}", ['cleared' => [$late]], $this->h)->assertUnprocessable()->assertJsonValidationErrors('cleared');
        $rent = $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->c['rent']])->value('id');
        $this->putJson("/api/v1/reconciliations/{$r}", ['cleared' => [$rent]], $this->h)->assertUnprocessable()->assertJsonValidationErrors('cleared');
        $this->putJson("/api/v1/reconciliations/{$r}", ['statement_date' => '2099-01-01'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('statement_date');

        $undeposited = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'undeposited_funds'])->value('id');
        $this->postJson("/api/v1/bank-accounts/{$undeposited}/reconciliations", ['statement_date' => '2026-11-09', 'ending_balance' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('statement_date');
    }

    public function test_viewers_read_reconciliations_and_cannot_reconcile(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/reconciliations", $this->h)->assertOk()->assertJsonPath('can.reconcile', false);
        $this->getJson("/api/v1/reconciliations/{$this->c['reconciliation']}", $this->h)->assertOk();
        $this->postJson("/api/v1/reconciliations/{$this->c['reconciliation']}/complete", [], $this->h)->assertForbidden();
    }
}
