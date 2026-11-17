<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 9c: the fixed asset register and the monthly depreciation run. The fixture's laptop: 36,000.00 over 36 months from December. */
final class FixedAssetTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $code): string
    {
        return $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'code' => $code])->value('id');
    }

    private function register(array $over): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/fixed-assets', [...['code' => 'X-1', 'name' => 'Asset', 'asset_account_id' => $this->account('1500'),
            'depreciation_account_id' => $this->account('1590'), 'expense_account_id' => $this->account('6700'), 'acquired_on' => '2026-11-05', 'cost' => '10000',
            'method' => 'straight_line', 'useful_life_months' => 12], ...$over], $this->h);
    }

    public function test_the_monthly_run_posts_each_assets_charge_and_goes_forward(): void
    {
        $laptop = $this->c['fixed_asset'];
        $this->getJson("/api/v1/fixed-assets/{$laptop}", $this->h)->assertOk()
            ->assertJsonPath('depreciation_starts_on', '2026-12-01')->assertJsonPath('book_value', '36000.00')->assertJsonPath('ahead.0', ['period_end' => '2026-12-31', 'amount' => '1000.00']);
        // November: it starts in December, so nothing yet.
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-11-30'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('period_end');

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00', 'UTC'));
        $this->getJson('/api/v1/depreciation/preview?period_end=2026-12-31', $this->h)->assertOk()->assertJsonPath('total', '1000.00')->assertJsonCount(1, 'lines');
        $run = $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $this->h)->assertCreated()->assertJsonPath('total', '1000.00')->assertJsonPath('assets', 1);
        $lines = collect($this->getJson("/api/v1/journal-entries/{$run->json('entry.id')}", $this->h)->assertOk()->assertJsonPath('source_type', 'depreciation')
            ->assertJsonPath('date', '2026-12-31')->json('lines'));
        $this->assertSame(['6700', '1000.00'], [$lines[0]['code'], $lines[0]['debit']]);
        $this->assertSame(['1590', '1000.00'], [$lines[1]['code'], $lines[1]['credit']]);

        $register = $this->getJson('/api/v1/fixed-assets', $this->h)->assertOk()->assertJsonPath('next_period_end', '2027-01-31')->assertJsonPath('can.depreciate', true);
        $row = collect($register->json('data'))->firstWhere('id', $laptop);
        $this->assertSame(['1000.00', '35000.00'], [$row['depreciated'], $row['book_value']]);
        $this->getJson("/api/v1/fixed-assets/{$laptop}", $this->h)->assertOk()->assertJsonCount(1, 'posted')->assertJsonPath('ahead.0.period_end', '2027-01-31');
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2027-01-30'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2027-02-28'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('period_end');   // after this month
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'action' => 'depreciation_posted'])->count());

        // Depreciated: how it wears out stays; what it is called and who keeps it may change.
        $edit = ['code' => 'IT-001', 'name' => 'Laptop (finance)', 'asset_account_id' => $this->account('1500'), 'depreciation_account_id' => $this->account('1590'),
            'expense_account_id' => $this->account('6700'), 'acquired_on' => '2026-11-03', 'cost' => '36000', 'method' => 'straight_line', 'useful_life_months' => 36,
            'depreciation_starts_on' => '2026-12-01', 'custodian' => 'Hana'];
        $this->putJson("/api/v1/fixed-assets/{$laptop}", [...$edit, 'useful_life_months' => 24], $this->h)->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->putJson("/api/v1/fixed-assets/{$laptop}", $edit, $this->h)->assertOk()->assertJsonPath('name', 'Laptop (finance)')->assertJsonPath('custodian', 'Hana');
    }

    public function test_an_asset_sold_posts_its_gain_or_loss_and_leaves_the_register(): void
    {
        $laptop = $this->c['fixed_asset'];
        Carbon::setTestNow(Carbon::parse('2027-02-10 09:00:00', 'UTC'));
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $this->h)->assertCreated();
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2027-01-31'], $this->h)->assertCreated();   // 2,000.00 depreciated, book value 34,000.00

        $this->postJson("/api/v1/fixed-assets/{$laptop}/dispose", ['date' => '2027-01-20', 'proceeds' => '30000', 'bank_account_id' => $this->c['bank']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('date');   // January's depreciation is already posted
        $this->postJson("/api/v1/fixed-assets/{$laptop}/dispose", ['date' => '2027-02-05', 'proceeds' => '30000'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
        $sold = $this->postJson("/api/v1/fixed-assets/{$laptop}/dispose", ['date' => '2027-02-05', 'proceeds' => '30000', 'bank_account_id' => $this->c['bank'], 'reason' => 'Sold to staff'], $this->h)
            ->assertOk()->assertJsonPath('status', 'disposed')->assertJsonPath('disposal.date', '2027-02-05')->assertJsonPath('disposal.proceeds', '30000.00')->assertJsonCount(0, 'ahead');
        $lines = collect($this->getJson("/api/v1/journal-entries/{$sold->json('disposal.entry_id')}", $this->h)->assertOk()->assertJsonPath('source_type', 'asset_disposal')->json('lines'))
            ->keyBy('code');
        $this->assertSame(['2000.00', '30000.00', '36000.00', '4000.00'], [$lines['1590']['debit'], $lines['1100']['debit'] ?? $lines->firstWhere('account_id', $this->c['bank'])['debit'],
            $lines['1500']['credit'], $lines['4960']['debit']]);   // a loss of 4,000.00: sold for 30,000.00, book value 34,000.00

        // Gone: not depreciated, not changed, not sold again.
        $this->getJson('/api/v1/depreciation/preview?period_end=2027-02-28', $this->h)->assertOk()->assertJsonCount(0, 'lines');
        $this->postJson("/api/v1/fixed-assets/{$laptop}/dispose", ['date' => '2027-02-06'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $scrapped = $this->register(['code' => 'S-1', 'name' => 'Old printer', 'cost' => '500', 'acquired_on' => '2026-11-05'])->assertCreated()->json('id');
        $gone = $this->postJson("/api/v1/fixed-assets/{$scrapped}/dispose", ['date' => '2027-02-06'], $this->h)->assertOk();
        $scrap = collect($this->getJson("/api/v1/journal-entries/{$gone->json('disposal.entry_id')}", $this->h)->assertOk()->json('lines'))->keyBy('code');
        $this->assertSame(['500.00', '500.00'], [$scrap['1500']['credit'], $scrap['4960']['debit']]);   // scrapped undepreciated: all of it lost
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $laptop, 'action' => 'asset_disposed'])->count());
    }

    public function test_reducing_balance_and_the_residual_value(): void
    {
        $press = $this->register(['code' => 'M-1', 'name' => 'Coffee roaster', 'cost' => '10000', 'method' => 'reducing_balance', 'annual_rate' => '0.25', 'useful_life_months' => null])
            ->assertCreated()->json('id');
        $this->getJson("/api/v1/fixed-assets/{$press}", $this->h)->assertOk()
            ->assertJsonPath('ahead.0.amount', '208.33')->assertJsonPath('ahead.1.amount', '203.99');   // 10,000 x 25% / 12, then 9,791.67 x 25% / 12
        $cup = $this->register(['code' => 'C-1', 'name' => 'Grinder', 'cost' => '1000', 'residual_value' => '990', 'useful_life_months' => 1])->assertCreated()->json('id');
        $this->getJson("/api/v1/fixed-assets/{$cup}", $this->h)->assertOk()->assertJsonCount(1, 'ahead')->assertJsonPath('ahead.0.amount', '10.00');
        $this->register(['code' => 'C-2', 'cost' => '1000', 'residual_value' => '600', 'opening_depreciation' => '500'])->assertUnprocessable()->assertJsonValidationErrors('residual_value');
        $this->register(['code' => 'IT-001'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->register(['code' => 'C-3', 'asset_account_id' => $this->account('6700')])->assertUnprocessable()->assertJsonValidationErrors('asset_account_id');
        $this->register(['code' => 'C-4', 'method' => 'reducing_balance', 'useful_life_months' => null])->assertUnprocessable()->assertJsonValidationErrors('annual_rate');
    }
}
