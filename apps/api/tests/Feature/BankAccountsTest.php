<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: bank, wallet and cash accounts, their registers, and transfers between them. A money account is a ledger
 * account of its own; transfers and deposits move money only between the company's own money accounts.
 */
final class BankAccountsTest extends TestCase
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

    public function test_money_accounts_are_listed_with_balances_and_new_ones_take_the_next_code(): void
    {
        $list = collect($this->getJson('/api/v1/bank-accounts', $this->h)->assertOk()->json('data'));
        $this->assertNotNull($list->firstWhere('id', $this->c['bank']));
        $this->assertNotNull($list->firstWhere('kind', 'undeposited_funds'));
        $this->assertNull($list->firstWhere('code', '1200'), 'the receivable is not a money account');

        $wallet = $this->postJson('/api/v1/bank-accounts', ['name' => 'telebirr merchant wallet', 'kind' => 'mobile_money',
            'bank_details' => ['provider' => 'telebirr', 'wallet_number' => '+251 911 000 222']], $this->h)->assertCreated()
            ->assertJsonPath('code', '1151')->assertJsonPath('kind', 'mobile_money')->assertJsonPath('currency', 'ETB')->assertJsonPath('balance', '0.00');
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'Dashen USD account', 'kind' => 'bank', 'currency' => 'USD',
            'bank_details' => ['bank_name' => 'Dashen Bank', 'account_number' => '5011-22334455']], $this->h)->assertCreated()
            ->assertJsonPath('code', '1101')->assertJsonPath('currency', 'USD');
        $this->postJson('/api/v1/bank-accounts', ['name' => 'Euro', 'kind' => 'bank', 'currency' => 'EUR'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->getJson("/api/v1/bank-accounts/{$this->c['rent']}", $this->h)->assertNotFound();   // an expense account

        // Bank details changed: audited as such. An empty account can be archived; one with money cannot.
        $this->putJson("/api/v1/bank-accounts/{$usd->json('id')}", ['bank_details' => ['bank_name' => 'Dashen Bank', 'account_number' => '5011-99999999']], $this->h)->assertOk();
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $usd->json('id'), 'action' => 'bank_details_changed'])->count());
        $this->putJson("/api/v1/bank-accounts/{$wallet->json('id')}", ['is_active' => false], $this->h)->assertOk()->assertJsonPath('is_active', false);
        $this->putJson("/api/v1/bank-accounts/{$this->c['bank']}", ['is_active' => false], $this->h)->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_a_transfer_moves_money_between_the_companys_own_accounts_and_shows_in_both_registers(): void
    {
        $cash = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => '1000'])->value('id');
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $cash,
            'amount' => '2500', 'fee' => '15', 'memo' => 'Petty cash top-up'], $this->h)->assertCreated();

        $register = $this->getJson("/api/v1/bank-accounts/{$cash}/register?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('opening', '0.00')->assertJsonPath('lines.0.in', '2500.00')->assertJsonPath('closing', '2500.00');
        $bank = $this->getJson("/api/v1/bank-accounts/{$this->c['bank']}/register?from=2026-11-10&to=2026-11-10", $this->h)->assertOk();
        $this->assertSame(['2500.00', '15.00'], collect($bank->json('lines'))->pluck('out')->all());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $this->c['bank'], 'action' => 'money_transferred'])->count());

        // Not to an expense account, not in the future, not to dollars without saying how many arrived.
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $this->c['rent'], 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->postJson('/api/v1/banking/transfers', ['date' => '2099-01-01', 'from_account_id' => $this->c['bank'], 'to_account_id' => $cash, 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'USD', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()
            ->assertJsonPath('bank_details', [])->json('id');   // no details: an empty object
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $usd, 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('amount_received');
    }

    public function test_money_moves_between_birr_and_dollars_at_the_days_rate_and_the_banks_margin_is_an_exchange_difference(): void
    {
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'CBE USD account', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $usd2 = $this->postJson('/api/v1/bank-accounts', ['name' => 'Dashen USD account', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => '2026-11-10', 'rate' => '154.50'], $this->h)->assertCreated();
        $fx = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'fx_gain_loss'])->value('id');
        $fxOf = fn (int $number) => $this->owner()->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where(['e.company_id' => $this->c['company'], 'e.number' => $number, 'l.account_id' => $fx])->selectRaw('sum(l.debit - l.credit)::numeric(20,2)::text AS n')->value('n');

        // Buy 1,000.00 dollars for 155,000.00 birr: worth 154,500.00 at the day's rate, so 500.00 went to the bank's margin.
        $buy = $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $usd,
            'amount' => '155000', 'amount_received' => '1000'], $this->h)->assertCreated();
        $this->assertSame('500.00', $fxOf($buy->json('number')));
        $this->getJson("/api/v1/bank-accounts/{$usd}/register?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('currency', 'USD')->assertJsonPath('lines.0.in', '1000.00')->assertJsonPath('closing', '1000.00');
        $this->getJson("/api/v1/bank-accounts/{$usd}", $this->h)->assertOk()->assertJsonPath('balance', '1000.00')->assertJsonPath('balance_base', '154500.00');

        // Sell 200.00 of them for 31,200.00 birr, more than the day's 30,900.00: a gain. Then move 100.00 to the other
        // dollar account, paying a 2.00 dollar fee: no exchange difference within one currency.
        $sell = $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $usd, 'to_account_id' => $this->c['bank'],
            'amount' => '200', 'amount_received' => '31200'], $this->h)->assertCreated();
        $this->assertSame('-300.00', $fxOf($sell->json('number')));
        $move = $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $usd, 'to_account_id' => $usd2,
            'amount' => '100', 'fee' => '2'], $this->h)->assertCreated();
        $this->assertNull($fxOf($move->json('number')));
        $this->getJson("/api/v1/bank-accounts/{$usd}", $this->h)->assertOk()->assertJsonPath('balance', '698.00');
        $this->getJson("/api/v1/bank-accounts/{$usd2}", $this->h)->assertOk()->assertJsonPath('balance', '100.00')->assertJsonPath('balance_base', '15450.00');

        // A rate given on the transfer wins; with no rate for the date and none given, the person is asked for one.
        $given = $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $usd,
            'amount' => '1550', 'amount_received' => '10', 'fx_rate' => '155'], $this->h)->assertCreated();
        $this->assertNull($fxOf($given->json('number')));
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-10-01', 'from_account_id' => $this->c['bank'], 'to_account_id' => $usd,
            'amount' => '1550', 'amount_received' => '10'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $usd, 'action' => 'money_transferred'])
            ->whereRaw("after->>'received_currency' = 'USD'")->count());

        // Shown the QuickBooks way: the dollar account's balance in dollars beside its value in birr (chart, trial balance),
        // each register line at its rate and in birr, and the general ledger running in dollars too.
        $register = $this->getJson("/api/v1/bank-accounts/{$usd}/register?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()
            ->assertJsonPath('base_currency', 'ETB')->assertJsonPath('lines.0.amount_base', '154500.00')->assertJsonPath('lines.0.rate', '154.500000');
        $this->assertSame('708.00', $register->json('closing'));
        $chart = collect($this->getJson('/api/v1/chart', $this->h)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('708.00', $chart[$usd]['foreign_balance']);
        $this->assertSame('109391.00', $chart[$usd]['balance']);
        $this->assertArrayNotHasKey('foreign_balance', $chart[$this->c['bank']]);
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-11-30', $this->h)->assertOk()->json('data'))->keyBy('account_id');
        $this->assertSame(['USD', '708.00', '109391.00'], [$tb[$usd]['currency'], $tb[$usd]['foreign_balance'], $tb[$usd]['debit']]);
        $this->assertNull($tb[$this->c['bank']]['currency']);
        $gl = collect($this->getJson('/api/v1/reports/general-ledger?from=2026-11-01&to=2026-11-30', $this->h)->assertOk()->json('accounts'))->keyBy('account_id');
        $this->assertSame(['USD', '0.00', '708.00'], [$gl[$usd]['currency'], $gl[$usd]['foreign_opening'], $gl[$usd]['foreign_closing']]);
        $this->assertSame(['1000.00', 'USD', '1000.00'], [$gl[$usd]['lines'][0]['foreign_amount'], $gl[$usd]['lines'][0]['currency'], $gl[$usd]['lines'][0]['foreign_balance']]);
        // The birr bank's side of a dollar purchase is birr: no foreign amount on it.
        $birr = collect($gl[$this->c['bank']]['lines'])->firstWhere('entry', $buy->json('number'));
        $this->assertNull($birr['foreign_amount']);
        $this->assertNull($birr['currency']);
    }

    public function test_a_deposit_banks_undeposited_receipts(): void
    {
        $this->postJson('/api/v1/banking/deposits', ['date' => '2026-11-10', 'bank_account_id' => $this->c['bank'], 'slip_number' => 'DS-0041',
            'receipts' => ['300', '200'], 'fee' => '5'], $this->h)->assertCreated();
        $undeposited = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'undeposited_funds'])->value('id');
        $this->getJson("/api/v1/bank-accounts/{$undeposited}/register?from=2026-11-10&to=2026-11-10", $this->h)->assertOk()->assertJsonPath('lines.0.out', '500.00');
        $this->postJson('/api/v1/banking/deposits', ['date' => '2026-11-10', 'bank_account_id' => $undeposited, 'receipts' => ['1']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
    }

    public function test_viewers_see_the_accounts_and_cannot_open_one(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/bank-accounts', $this->h)->assertOk()->assertJsonPath('can.manage', false);
        $this->postJson('/api/v1/bank-accounts', ['name' => 'x', 'kind' => 'cash'], $this->h)->assertForbidden();
    }
}
