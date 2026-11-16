<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: the general ledger agrees with the bank register, the trial balance and itself. */
final class GeneralLedgerTest extends TestCase
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

    public function test_one_account_agrees_with_its_register(): void
    {
        $gl = $this->getJson("/api/v1/reports/general-ledger?from=2026-11-05&to=2026-11-30&account_id={$this->c['bank']}", $this->h)->assertOk()
            ->assertJsonPath('truncated', false)->assertJsonCount(1, 'accounts');
        $register = $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/register?from=2026-11-05&to=2026-11-30", $this->h)->assertOk();
        $account = $gl->json('accounts.0');
        $this->assertSame([$register->json('opening'), $register->json('closing')], [$account['opening'], $account['closing']]);
        $this->assertCount(count($register->json('lines')), $account['lines']);
        $this->assertSame($account['closing'], end($account['lines'])['balance'], 'the running balance ends at the closing balance');
        $this->assertNotNull($account['lines'][0]['entry_id']);
    }

    public function test_every_account_balances_to_nothing_and_agrees_with_the_trial_balance(): void
    {
        $gl = collect($this->getJson('/api/v1/reports/general-ledger?from=2026-11-01&to=2026-12-31', $this->h)->assertOk()->json('accounts'));
        $sum = fn (string $key) => number_format($gl->sum(fn ($a) => (float) $a[$key]), 2, '.', '');
        $this->assertSame('0.00', $sum('opening'));
        $this->assertSame('0.00', $sum('closing'), 'debits equal credits across the books');
        $this->assertSame($sum('debits'), $sum('credits'));

        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31', $this->h)->assertOk()->json('data'))->keyBy('account_id');
        foreach ($gl as $a) {
            $row = $tb[$a['account_id']] ?? null;
            $net = $row ? number_format((float) $row['debit'] - (float) $row['credit'], 2, '.', '') : '0.00';
            $this->assertSame($net, number_format((float) $a['closing'], 2, '.', ''), "{$a['code']} agrees with the trial balance");
        }
    }

    public function test_lines_of_one_location_and_what_is_refused(): void
    {
        $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-09', 'memo' => 'Rent of the head office', 'lines' => [
            ['account_id' => $this->c['rent'], 'debit' => '300', 'location_id' => $this->c['dimension']], ['account_id' => $this->c['bank'], 'credit' => '300'],
        ]], $this->h)->assertCreated();
        $gl = $this->getJson("/api/v1/reports/general-ledger?from=2026-11-01&to=2026-11-30&location_id={$this->c['dimension']}", $this->h)->assertOk();
        $this->assertSame(['6100'], collect($gl->json('accounts'))->pluck('code')->all());
        $this->assertSame(['300.00', 'Rent of the head office'], [$gl->json('accounts.0.closing'), $gl->json('accounts.0.lines.0.memo')]);

        $this->getJson('/api/v1/reports/general-ledger?from=2026-11-30&to=2026-11-01', $this->h)->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/v1/reports/general-ledger?from=2026-11-01&to=2026-11-30&class_id={$this->c['dimension']}", $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');   // a location, not a class
        $this->getJson('/api/v1/reports/general-ledger?from=2026-11-01&to=2026-11-30&account_id=00000000-0000-0000-0000-000000000001', $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }
}
