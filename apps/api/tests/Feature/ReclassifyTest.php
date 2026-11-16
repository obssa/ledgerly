<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6, accountant tools: lines posted to the wrong account move to the right one in bulk, by one manual journal. */
final class ReclassifyTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $repairs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->repairs = $this->postJson('/api/v1/accounts', ['code' => '6150', 'name' => 'Repairs', 'type' => 'expense'], $this->h)->assertCreated()->json('id');
    }

    /** Post a journal to rent and return its rent line's id. */
    private function rentLine(string $date, string $amount, string $description): string
    {
        $entry = $this->postJson('/api/v1/journal-entries', ['date' => $date, 'memo' => "Paid: {$description}", 'lines' => [
            ['account_id' => $this->c['rent'], 'debit' => $amount, 'description' => $description], ['account_id' => $this->c['equity'], 'credit' => $amount],
        ]], $this->h)->assertCreated()->json('id');

        return $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $this->c['rent']])->value('id');
    }

    private function reclassify(array $over): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/journal-entries/reclassify', [...['to_account_id' => $this->repairs, 'date' => '2026-11-12'], ...$over], $this->h);
    }

    public function test_lines_move_to_another_account_by_one_journal(): void
    {
        $roof = $this->rentLine('2026-11-09', '1200', 'Roof fixed');
        $door = $this->rentLine('2026-11-10', '300.50', 'Door lock');
        $entry = $this->reclassify(['line_ids' => [$door, $roof]])->assertCreated()
            ->assertJsonPath('source_type', 'manual')->assertJsonPath('total', '1500.50')->assertJsonPath('date', '2026-11-12')
            ->assertJsonCount(4, 'lines')->assertJsonPath('lines.0.code', '6100')->assertJsonPath('lines.0.credit', '1200.00')
            ->assertJsonPath('lines.1.code', '6150')->assertJsonPath('lines.1.debit', '1200.00');
        $this->assertStringStartsWith('Reclassification from 6100', $entry->json('memo'));
        $this->assertStringEndsWith(': Roof fixed', (string) $entry->json('lines.0.description'));

        $gl = $this->getJson("/api/v1/reports/general-ledger?from=2026-11-01&to=2026-11-30&account_id={$this->repairs}", $this->h)->assertOk();
        $this->assertSame('1500.50', $gl->json('accounts.0.closing'));
        $this->assertNotNull($gl->json('accounts.0.lines.0.line_id'));
        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'journal_reclassified'])->first();
        $this->assertSame($this->repairs, json_decode((string) $audit->after, true)['account_id']);
    }

    public function test_what_a_reclassification_refuses(): void
    {
        $roof = $this->rentLine('2026-11-09', '1200', 'Roof fixed');
        $equityLine = $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $this->c['equity']])->orderByDesc('entry_date')->value('id');
        $this->reclassify(['line_ids' => [$roof, $equityLine]])->assertUnprocessable()->assertJsonValidationErrors('line_ids');
        $this->reclassify(['line_ids' => [$roof], 'to_account_id' => $this->c['rent']])->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->reclassify(['line_ids' => [$roof], 'date' => '2026-11-08'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->reclassify(['line_ids' => [(string) \Illuminate\Support\Str::uuid()]])->assertUnprocessable()->assertJsonValidationErrors('line_ids');

        // Onto an account kept by documents: refused like any manual journal.
        $receivable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $this->reclassify(['line_ids' => [$roof], 'to_account_id' => $receivable])->assertUnprocessable()->assertJsonValidationErrors('lines.1.account_id');

        // A reversed entry's line stays where it is.
        $entry = $this->owner()->table('journal_lines')->where('id', $roof)->value('journal_entry_id');
        $this->postJson("/api/v1/journal-entries/{$entry}/reverse", ['date' => '2026-11-10', 'reason' => 'Posted twice'], $this->h)->assertCreated();
        $this->reclassify(['line_ids' => [$roof]])->assertUnprocessable()->assertJsonValidationErrors('line_ids');
        $this->assertSame(0, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'journal_reclassified'])->count());
    }

    public function test_above_the_approval_limit_it_waits_for_approval(): void
    {
        $roof = $this->rentLine('2026-11-09', '1200', 'Roof fixed');
        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'approval' => ['journal_above' => '1000']], $this->h)->assertOk();
        $this->reclassify(['line_ids' => [$roof]])->assertStatus(202)->assertJsonPath('request.total', '1200.00')->assertJsonPath('request.status', 'pending');
    }
}
