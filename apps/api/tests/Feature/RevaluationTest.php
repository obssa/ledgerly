<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6: month-end revaluation. On 9 Nov the company holds 100.00 USD (booked at 150, 15,000.00 birr), is owed a
 * 200.00 USD invoice (30,000.00) and owes a 50.00 USD bill (7,500.00). At 160 on 10 Nov: the bank and the invoice are
 * worth 1,000.00 and 2,000.00 more, the bill costs 500.00 more, a net unrealised gain of 2,500.00, reversed on 11 Nov.
 */
final class RevaluationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private string $usd;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];

        $this->usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'CBE USD', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/v1/banking/transfers', ['date' => '2026-11-09', 'from_account_id' => $this->c['bank'], 'to_account_id' => $this->usd,
            'amount' => '15000', 'amount_received' => '100', 'fx_rate' => '150'], $this->h)->assertCreated();
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-09', 'currency' => 'USD', 'fx_rate' => '150',
            'lines' => [['description' => 'Coffee for export', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $this->c['sales']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $this->h)->assertOk();
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-09', 'currency' => 'USD', 'fx_rate' => '150', 'reference' => 'US-1',
            'lines' => [['description' => 'Sample shipping', 'quantity' => '1', 'unit_price' => '50', 'account_id' => $this->c['rent']]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $this->h)->assertOk();
    }

    private function baseBalance(string $account, string $date): string
    {
        return $this->getJson("/api/v1/reports/general-ledger?from=2026-01-01&to={$date}&account_id={$account}", $this->h)->assertOk()->json('accounts.0.closing');
    }

    public function test_foreign_balances_are_restated_at_the_days_rate_and_the_next_day_put_back(): void
    {
        $preview = $this->getJson('/api/v1/revaluations/preview?as_of=2026-11-10&rates[USD]=160', $this->h)->assertOk()
            ->assertJsonPath('rates.USD', '160')->assertJsonPath('net', '2500.00');
        $this->assertEquals(['account' => '1000.00', 'invoice' => '2000.00', 'bill' => '-500.00'],
            collect($preview->json('lines'))->mapWithKeys(fn ($l) => [$l['kind'] => $l['difference']])->all());

        $run = $this->postJson('/api/v1/revaluations', ['as_of' => '2026-11-10', 'rates' => ['USD' => '160']], $this->h)->assertCreated()
            ->assertJsonPath('net', '2500.00')->assertJsonPath('reversal.date', '2026-11-11');
        $this->assertSame('16000.00', $this->baseBalance($this->usd, '2026-11-10'));
        $this->assertSame('15000.00', $this->baseBalance($this->usd, '2026-11-11'));
        $fx = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'fx_gain_loss'])->value('id');
        $this->assertSame('-2500.00', $this->baseBalance($fx, '2026-11-10'), 'a gain, on the credit side');
        $this->getJson("/api/v1/bank-accounts/{$this->usd}/register?from=2026-11-01&to=2026-11-30", $this->h)->assertOk()->assertJsonPath('closing', '100.00');   // dollars untouched

        $this->getJson('/api/v1/revaluations', $this->h)->assertOk()->assertJsonPath('data.0.id', $run->json('id'))->assertJsonPath('data.0.net', '2500.00')
            ->assertJsonPath('data.0.reversal.number', $run->json('reversal.number'));
        $this->postJson('/api/v1/revaluations', ['as_of' => '2026-11-10', 'rates' => ['USD' => '161']], $this->h)->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $run->json('id'), 'action' => 'revalued'])->count());
    }

    public function test_the_rate_and_the_date_are_required_as_they_should_be(): void
    {
        $this->getJson('/api/v1/revaluations/preview?as_of=2026-11-10', $this->h)->assertUnprocessable()->assertJsonValidationErrors('rates.USD');   // last rate 2 Nov: older than a week
        $this->getJson('/api/v1/revaluations/preview?as_of=2026-11-08', $this->h)->assertOk()->assertJsonPath('lines', []);   // nothing foreign yet on 8 Nov
        $this->postJson('/api/v1/revaluations', ['as_of' => '2026-11-08'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Addis_Ababa')))->format('Y-m-d');
        $this->postJson('/api/v1/revaluations', ['as_of' => $today, 'rates' => ['USD' => '160']], $this->h)->assertUnprocessable()->assertJsonValidationErrors('as_of');
    }

    public function test_viewers_see_the_runs_and_do_not_revalue(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/revaluations', $this->h)->assertOk()->assertJsonPath('can.revalue', false);
        $this->getJson('/api/v1/revaluations/preview?as_of=2026-11-10&rates[USD]=160', $this->h)->assertForbidden();
    }
}
