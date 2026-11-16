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

        // Not to an expense account, not in the future, not between dollars and birr yet.
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $this->c['rent'], 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->postJson('/api/v1/banking/transfers', ['date' => '2099-01-01', 'from_account_id' => $this->c['bank'], 'to_account_id' => $cash, 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'USD', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()
            ->assertJsonPath('bank_details', [])->json('id');   // no details: an empty object
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-10', 'from_account_id' => $this->c['bank'], 'to_account_id' => $usd, 'amount' => '1'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
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
