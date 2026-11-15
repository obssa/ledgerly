<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, currencies: foreign currencies are turned on by an owner or admin, rates are entered and corrected by an
 * accountant with an audit record, and a foreign-currency invoice takes the rate of its own date and keeps it.
 * The company fixture (CompanyFixtures) already works in USD with a rate for 2 Nov 2026.
 */
final class CurrencyTest extends TestCase
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

    public function test_the_owner_turns_currencies_on_and_off_but_never_the_base_currency(): void
    {
        $this->putJson('/api/v1/currencies', ['currency' => 'EUR', 'enabled' => true], $this->h)->assertOk();
        $this->putJson('/api/v1/currencies', ['currency' => 'ETB', 'enabled' => true], $this->h)->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->putJson('/api/v1/currencies', ['currency' => 'EUR', 'enabled' => false], $this->h)->assertOk();

        $this->getJson('/api/v1/currencies', $this->h)->assertOk()
            ->assertJsonPath('base_currency', 'ETB')
            ->assertJsonPath('data', [
                ['currency' => 'EUR', 'is_enabled' => false, 'latest_rate' => null, 'latest_rate_date' => null],
                ['currency' => 'USD', 'is_enabled' => true, 'latest_rate' => '134.523333', 'latest_rate_date' => '2026-11-02'],
            ]);
        $this->assertSame(['currency_enabled', 'currency_enabled', 'currency_disabled'],
            $this->owner()->table('audit_log')->where('company_id', $this->c['company'])->where('subject_type', 'currency')->orderBy('id')->pluck('action')->all());
    }

    public function test_an_accountant_enters_and_corrects_rates_and_every_change_is_audited(): void
    {
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'sanctum');

        $this->rate('2026-11-03', '135.10')->assertCreated();
        $this->rate('2026-11-03', '135.20')->assertOk();
        $this->rate('2026-11-03', '135.20')->assertOk(); // the same value again changes nothing
        $this->rate(now()->addDay()->format('Y-m-d'), '135')->assertUnprocessable()->assertJsonValidationErrors('rate_date');
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'EUR', 'rate_date' => '2026-11-03', 'rate' => '150'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->rate('2026-11-03', '0')->assertUnprocessable();

        $this->getJson('/api/v1/exchange-rates?currency=USD&from=2026-11-01&to=2026-11-30', $this->h)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rate_date', '2026-11-03')
            ->assertJsonPath('data.0.rate', '135.200000');

        $trail = $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'subject_type' => 'exchange_rate'])
            ->where('user_id', $accountant->getKey())->orderBy('id')->get(['action', 'before', 'after']);
        $this->assertSame(['rate_added', 'rate_corrected'], $trail->pluck('action')->all());
        $this->assertSame('135.100000', json_decode($trail[1]->before, true)['rate']);
        $this->assertSame('135.20', json_decode($trail[1]->after, true)['rate']);
    }

    public function test_a_foreign_currency_invoice_takes_the_rate_of_its_date_and_keeps_it(): void
    {
        $this->rate('2026-10-30', '133.000000')->assertCreated();

        // 4 Nov: the newest rate on or before the date is 2 Nov.
        $id = $this->invoice(['number' => 'INV-USD-1', 'issue_date' => '2026-11-04', 'currency' => 'USD'])->assertCreated()
            ->assertJsonPath('fx_rate', '134.523333')->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();

        $entry = $this->owner()->table('journal_entries')->where(['company_id' => $this->c['company'], 'source_id' => $id])->sole();
        $this->assertSame('USD', $entry->currency);
        $this->assertSame('134.523333', (string) $entry->fx_rate);
        // 2 x 500 USD + 5% levy = 1,050 USD; x 134.523333 = 141,249.49965 -> 141,249.50 ETB receivable.
        $this->assertSame('141249.50', (string) $this->owner()->table('journal_lines')->where('journal_entry_id', $entry->id)->max('debit'));

        // Correcting the day's rate later leaves the posted invoice as it was.
        $this->rate('2026-11-02', '140')->assertOk();
        $this->assertSame('134.523333', (string) $this->owner()->table('documents')->where('id', $id)->value('fx_rate'));
    }

    public function test_without_a_recent_rate_the_invoice_asks_for_one(): void
    {
        $this->invoice(['number' => 'INV-USD-2', 'issue_date' => '2026-11-20', 'currency' => 'USD'])
            ->assertUnprocessable()->assertJsonValidationErrors('fx_rate');
        $this->invoice(['number' => 'INV-USD-2', 'issue_date' => '2026-11-20', 'currency' => 'USD', 'fx_rate' => '136.25'])
            ->assertCreated()->assertJsonPath('fx_rate', '136.250000');
    }

    public function test_an_invoice_uses_only_the_companys_currencies_and_rate_1_for_the_base_currency(): void
    {
        $this->invoice(['number' => 'INV-EUR', 'currency' => 'EUR', 'fx_rate' => '150'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->invoice(['number' => 'INV-ETB', 'currency' => 'ETB', 'fx_rate' => '5'])->assertCreated()->assertJsonPath('fx_rate', '1.000000');
    }

    private function rate(string $date, string $rate): TestResponse
    {
        return $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate_date' => $date, 'rate' => $rate], $this->h);
    }

    /** @param array<string, mixed> $over */
    private function invoice(array $over): TestResponse
    {
        return $this->postJson('/api/v1/invoices', [
            'contact_id' => $this->c['contact'], 'number' => 'INV-X', 'issue_date' => '2026-11-04', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '2', 'unit_price' => '500', 'account_id' => $this->c['sales'], 'tax_rate_id' => $this->c['tax_rate']]],
            ...$over,
        ], $this->h);
    }

    private function member(string $role): User
    {
        $user = $this->createUser(ucfirst($role), "{$role}@example.test");
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }
}
