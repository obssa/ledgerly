<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 5: petty cash. A box with a float, topped up from the bank, spent through vouchers (expenses), counted by name. */
final class PettyCashTest extends TestCase
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

    public function test_a_float_is_topped_up_spent_and_counted_and_the_books_hold_what_was_counted(): void
    {
        $this->postJson('/api/v1/bank-accounts', ['name' => 'Bank float', 'kind' => 'bank', 'float_amount' => '100'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('float_amount');
        $box = $this->postJson('/api/v1/bank-accounts', ['name' => 'Office petty cash', 'kind' => 'cash', 'float_amount' => '2000'], $this->h)->assertCreated()
            ->assertJsonPath('float_amount', '2000.00')->assertJsonPath('top_up', '2000.00')->json('id');

        // Topped up from the bank, then a 150.00 voucher for taxi fares.
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-09', 'from_account_id' => $this->c['bank'], 'to_account_id' => $box, 'amount' => '2000'], $this->h)->assertCreated();
        $this->getJson("/api/v1/bank-accounts/{$box}", $this->h)->assertOk()->assertJsonPath('top_up', '0.00');
        $this->postJson('/api/v1/expenses', ['contact_id' => $this->c['supplier'], 'paid_from_account_id' => $box, 'payment_method' => 'cash', 'reference' => 'PCV-001',
            'issue_date' => '2026-11-10', 'currency' => 'ETB', 'lines' => [['description' => 'Taxi fares', 'quantity' => '1', 'unit_price' => '150', 'account_id' => $this->c['rent']]]], $this->h)
            ->assertCreated();
        $this->getJson("/api/v1/bank-accounts/{$box}", $this->h)->assertOk()->assertJsonPath('balance', '1850.00')->assertJsonPath('top_up', '150.00');

        // Counted: nine 200s and four 10s, 1,840.00. The 10.00 short goes to cash over and short; the books now say 1,840.00.
        $count = fn (array $body) => $this->postJson("/api/v1/bank-accounts/{$box}/cash-counts", [...['counted_on' => '2026-11-10', 'counted_by_name' => 'Meron Alemu'], ...$body], $this->h);
        $count(['denominations' => ['200' => 9, '10' => 4], 'counted' => '1850'])->assertUnprocessable()->assertJsonValidationErrors('counted');
        $count(['denominations' => ['200' => 9, '10' => 4, '5' => 0], 'note' => 'Drawer count at close'])->assertCreated()
            ->assertJsonPath('counted', '1840.00')->assertJsonPath('book_balance', '1850.00')->assertJsonPath('difference', '-10.00');
        $this->getJson("/api/v1/bank-accounts/{$box}", $this->h)->assertOk()->assertJsonPath('balance', '1840.00')->assertJsonPath('top_up', '160.00');
        $this->getJson("/api/v1/bank-accounts/{$box}/register?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('account.balance', '1840.00')->assertJsonPath('account.top_up', '160.00')->assertJsonPath('closing', '1840.00');
        $overShort = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'cash_over_short'])->value('id');
        $this->assertSame('10.00', (string) $this->owner()->table('journal_lines')->where(['company_id' => $this->c['company'], 'account_id' => $overShort])->sum('debit'));

        // The same again: nothing to post. Counts are listed newest first, with the counter and the notes.
        $count(['counted' => '1840'])->assertCreated()->assertJsonPath('difference', '0.00')->assertJsonPath('entry', null);
        $list = $this->getJson("/api/v1/bank-accounts/{$box}/cash-counts", $this->h)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can.count', true);
        $this->assertEquals(['Meron Alemu', ['200' => 9, '10' => 4]], [$list->json('data.1.counted_by'), $list->json('data.1.denominations')]);
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['subject_id' => $box, 'action' => 'cash_counted'])->count());

        // Not without a name, not tomorrow, not a bank account, not a made-up coin.
        $count(['counted_by_name' => '', 'counted' => '1'])->assertUnprocessable()->assertJsonValidationErrors('counted_by_name');
        $count(['counted_on' => '2099-01-01', 'counted' => '1'])->assertUnprocessable()->assertJsonValidationErrors('counted_on');
        $count(['denominations' => ['abc' => 1]])->assertUnprocessable()->assertJsonValidationErrors('denominations');
        $this->postJson("/api/v1/bank-accounts/{$this->c['bank']}/cash-counts", ['counted_on' => '2026-11-10', 'counted_by_name' => 'Meron Alemu', 'counted' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('counted');
    }

    public function test_viewers_read_the_counts_and_cannot_count(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson("/api/v1/bank-accounts/{$this->c['petty_cash']}/cash-counts", $this->h)->assertOk()->assertJsonPath('can.count', false)->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/bank-accounts/{$this->c['petty_cash']}/cash-counts", ['counted_on' => '2026-11-10', 'counted_by_name' => 'Abel', 'counted' => '0'], $this->h)->assertForbidden();
    }
}
