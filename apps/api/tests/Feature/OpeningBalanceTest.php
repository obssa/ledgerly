<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, opening balances: the trial balance on the conversion date, posted as one journal entry; a difference
 * goes to Opening balance equity and is flagged; entering them again reverses and replaces; control accounts come
 * from their detail. Roles: PermissionMatrixTest. Other companies' ids: TenantIsolationTest and the test below.
 */
final class OpeningBalanceTest extends TestCase
{
    use RefreshLedgerDatabase;

    private string $company;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createUser('Hana', 'hana@example.test'), 'sanctum');
        $this->company = $this->postJson('/api/v1/companies', ['legal_name' => 'Abyssinia Coffee', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])
            ->assertCreated()->json('id');
        $this->h = ['X-Company-Id' => $this->company];
    }

    public function test_opening_balances_need_the_conversion_date(): void
    {
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '1000']])
            ->assertUnprocessable()->assertJsonValidationErrors('conversion_date');
    }

    public function test_they_post_on_the_conversion_date_and_a_difference_goes_to_opening_balance_equity(): void
    {
        $this->setConversionDate('2026-07-07');

        $this->enter([
            ['account_id' => $this->account('bank'), 'debit' => '50000'],
            ['account_id' => $this->account('vat_payable'), 'credit' => '5000'],
            ['account_id' => $this->account('retained_earnings'), 'credit' => '40000'],
            ['account_id' => $this->account('bank_charges'), 'debit' => '0'],   // empty rows are ignored
        ])->assertOk()
            ->assertJsonPath('conversion_date', '2026-07-07')
            ->assertJsonPath('journal_entry.entry_date', '2026-07-07')
            ->assertJsonPath('difference', '5000.00')
            ->assertJsonPath('can.enter', true)
            ->assertJsonCount(3, 'lines');

        $entry = $this->owner()->table('journal_entries')->where(['company_id' => $this->company, 'source_type' => 'opening'])->sole();
        $this->assertSame('5000.00', (string) $this->owner()->table('journal_lines')
            ->where(['journal_entry_id' => $entry->id, 'account_id' => $this->account('opening_balance_equity')])->value('credit'));
        $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-07-07', $this->h)->assertOk()->assertJsonPath('equation.balanced', true);

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'action' => 'opening_balances_entered'])->sole();
        $this->assertSame('5000.00', json_decode($audit->after, true)['difference']);
    }

    public function test_entering_them_again_reverses_the_previous_entry_and_posts_the_new_one(): void
    {
        $this->setConversionDate('2026-07-07');
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '1000'], ['account_id' => $this->account('retained_earnings'), 'credit' => '900']])->assertOk();

        $this->enter([['account_id' => $this->account('bank'), 'debit' => '1200'], ['account_id' => $this->account('retained_earnings'), 'credit' => '1200']])
            ->assertOk()->assertJsonPath('difference', '0.00')
            ->assertJsonPath('lines', [
                ['account_id' => $this->account('bank'), 'debit' => '1200.00', 'credit' => '0.00'],
                ['account_id' => $this->account('retained_earnings'), 'debit' => '0.00', 'credit' => '1200.00'],
            ]);

        $entries = $this->owner()->table('journal_entries')->where('company_id', $this->company)->orderBy('number')->get(['source_type', 'status']);
        $this->assertSame([['opening', 'reversed'], ['reversal', 'posted'], ['opening', 'posted']],
            $entries->map(fn ($e) => [$e->source_type, $e->status])->all());
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->company, 'action' => 'opening_balances_replaced'])->count());

        // The bank balance is the new figure, not both added up.
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-07-07', $this->h)->assertOk()->json('data'));
        $this->assertSame('1200.00', (string) $tb->firstWhere('account_id', $this->account('bank'))['debit']);
    }

    public function test_receivables_payables_and_stock_come_from_their_detail_not_one_figure(): void
    {
        $this->setConversionDate('2026-07-07');
        foreach (['accounts_receivable', 'accounts_payable', 'inventory'] as $key) {
            $this->enter([['account_id' => $this->account($key), 'debit' => '100'], ['account_id' => $this->account('retained_earnings'), 'credit' => '100']])
                ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        }
        $this->assertSame(0, $this->owner()->table('journal_entries')->where('company_id', $this->company)->count());
    }

    public function test_a_line_is_a_debit_or_a_credit_and_each_account_appears_once(): void
    {
        $this->setConversionDate('2026-07-07');
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '10', 'credit' => '10']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '10'], ['account_id' => $this->account('bank'), 'credit' => '10']])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.account_id');
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '0']])->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_another_companys_account_is_not_found(): void
    {
        $this->setConversionDate('2026-07-07');
        $other = $this->createUser('Other', 'other@example.test');
        $this->actingAs($other, 'sanctum');
        $otherCompany = $this->postJson('/api/v1/companies', ['legal_name' => 'Other', 'country_code' => 'ET', 'fiscal_year_start_month' => 7])->json('id');
        $otherBank = $this->owner()->table('accounts')->where(['company_id' => $otherCompany, 'system_key' => 'bank'])->value('id');

        $this->actingAs($this->hana(), 'sanctum');
        $this->enter([['account_id' => $otherBank, 'debit' => '10'], ['account_id' => $this->account('retained_earnings'), 'credit' => '10']])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->assertSame(0, $this->owner()->table('journal_lines')->where('account_id', $otherBank)->count());
    }

    public function test_a_closed_conversion_date_refuses_them(): void
    {
        $this->setConversionDate('2026-07-07');
        $this->putJson('/api/v1/company/period-lock', ['books_closed_until' => '2026-07-31'], $this->h)->assertOk();
        $this->enter([['account_id' => $this->account('bank'), 'debit' => '10'], ['account_id' => $this->account('retained_earnings'), 'credit' => '10']])
            ->assertUnprocessable();
    }

    /** @param list<array<string, string>> $lines */
    private function enter(array $lines): TestResponse
    {
        return $this->putJson('/api/v1/opening-balances', ['lines' => $lines], $this->h);
    }

    private function setConversionDate(string $date): void
    {
        $version = $this->getJson('/api/v1/company', $this->h)->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'conversion_date' => $date], $this->h)->assertOk();
    }

    private function account(string $key): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->company, 'system_key' => $key])->value('id');
    }

    private function hana(): User
    {
        return User::query()->where('email', 'hana@example.test')->firstOrFail();
    }
}
