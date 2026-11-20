<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9c (092): revaluation. The laptop (36,000.00 over 36 months, 1,000.00 charged for December, carried at
 * 35,000.00) is revalued up to 42,000.00 (7,000.00 to the surplus, other comprehensive income), down to 30,000.00
 * (7,000.00 off the surplus, 5,000.00 through profit or loss), then up to 33,000.00 (3,000.00 back through profit or
 * loss). Each time the next month's charge is the carrying amount over the 35 months to go; the cash flow still
 * balances; selling it takes off its revalued cost. Another company finds none of it.
 */
final class AssetRevaluationTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_asset_is_revalued_through_the_surplus_and_profit_or_loss(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $laptop = $c['fixed_asset'];
        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00', 'UTC'));
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $h)->assertCreated()->assertJsonPath('total', '1000.00');
        $surplus = $this->postJson('/api/v1/accounts', ['code' => '3400', 'name' => 'Revaluation surplus', 'type' => 'equity'], $h)->assertCreated()->json('id');
        $other = $this->postJson('/api/v1/accounts', ['code' => '3410', 'name' => 'Other reserve', 'type' => 'equity'], $h)->assertCreated()->json('id');
        $this->assertContains($surplus, collect($this->getJson("/api/v1/fixed-assets/{$laptop}", $h)->assertOk()->json('surplus_accounts'))->pluck('id')->all());

        $revalue = fn (array $body) => $this->postJson("/api/v1/fixed-assets/{$laptop}/revalue",
            [...['date' => '2027-01-05', 'surplus_account_id' => $surplus, 'reason' => "Valuer's report"], ...$body], $h);
        $revalue(['fair_value' => '42000', 'surplus_account_id' => $c['rent']])->assertUnprocessable()->assertJsonValidationErrors('surplus_account_id');
        $revalue(['fair_value' => '35000'])->assertUnprocessable()->assertJsonValidationErrors('fair_value');   // already carried at that
        $revalue(['fair_value' => '42000', 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Up: 7,000.00 to the surplus. Dr the cost account, Cr the surplus; January's charge is 42,000.00 over 35 months.
        $up = $revalue(['fair_value' => '42000'])->assertCreated()->assertJsonPath('book_value', '42000.00')->assertJsonPath('surplus', '7000.00')
            ->assertJsonPath('ahead.0.amount', '1200.00')->json();
        $lines = collect($this->getJson("/api/v1/journal-entries/{$up['revaluations'][0]['entry_id']}", $h)->assertOk()->assertJsonPath('source_type', 'asset_revaluation')->json('lines'));
        $this->assertSame('7000.00', $lines->firstWhere('account_id', $up['asset_account']['id'])['debit']);
        $this->assertSame('7000.00', $lines->firstWhere('account_id', $surplus)['credit']);
        $pl = $this->getJson('/api/v1/reports/profit-and-loss?from=2027-01-01&to=2027-01-31', $h)->assertOk()->json('totals');
        $this->assertSame('7000.00', $pl['other_comprehensive_income']['current']);
        $this->assertSame(bcadd($pl['net_profit']['current'], '7000.00', 2), $pl['total_comprehensive_income']['current']);
        $this->getJson('/api/v1/fixed-assets', $h)->assertOk()->assertJsonFragment(['id' => $laptop, 'book_value' => '42000.00']);

        // The surplus stays on its account; down to 30,000.00: 7,000.00 off the surplus, 5,000.00 through profit or loss.
        $revalue(['fair_value' => '30000', 'surplus_account_id' => $other])->assertUnprocessable()->assertJsonValidationErrors('surplus_account_id');
        $down = $revalue(['date' => '2027-01-06', 'fair_value' => '30000'])->assertCreated()->assertJsonPath('book_value', '30000.00')
            ->assertJsonPath('surplus', '0.00')->assertJsonPath('ahead.0.amount', '857.14')->json();
        $this->assertSame(['-7000.00', '-5000.00'], [$down['revaluations'][1]['to_surplus'], $down['revaluations'][1]['to_profit']]);

        // Back up to 33,000.00: it reverses 3,000.00 of what was charged to profit or loss; none to the surplus.
        $again = $revalue(['date' => '2027-01-07', 'fair_value' => '33000'])->assertCreated()->assertJsonPath('book_value', '33000.00')->json();
        $this->assertSame(['0.00', '3000.00'], [$again['revaluations'][2]['to_surplus'], $again['revaluations'][2]['to_profit']]);
        $this->getJson('/api/v1/depreciation/preview?period_end=2027-01-31', $h)->assertOk()->assertJsonPath('total', '942.86');
        $this->getJson('/api/v1/reports/cash-flow?from=2027-01-01&to=2027-01-31', $h)->assertOk()->assertJsonPath('balanced', true);
        $notes = $this->getJson('/api/v1/reports/notes?from=2027-01-01&to=2027-01-31', $h)->assertOk()->json('ppe');
        $this->assertSame(['10000.00', '12000.00'], [$notes['cost']['revaluations'], $notes['depreciation']['revaluations']]);
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $laptop, 'action' => 'asset_revalued'])->count());

        // Scrapped: the entry takes off the revalued cost (36,000 + 7,000 + 3,000) and the depreciation with the fall (1,000 + 12,000).
        $this->postJson("/api/v1/fixed-assets/{$laptop}/dispose", ['date' => '2027-01-09', 'proceeds' => '0', 'reason' => 'Stolen'], $h)->assertOk();
        $disposal = $this->owner()->table('fixed_assets')->where('id', $laptop)->value('disposal_entry_id');
        $moved = $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'journal_entry_id' => $disposal])->get();
        $this->assertSame('46000.00', (string) $moved->firstWhere('account_id', $up['asset_account']['id'])->credit);
        $this->assertSame('13000.00', (string) $moved->firstWhere('account_id', $up['depreciation_account']['id'] ?? $this->owner()->table('fixed_assets')->where('id', $laptop)->value('depreciation_account_id'))->debit);

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/fixed-assets/{$laptop}/revalue", ['date' => '2027-01-05', 'fair_value' => '1', 'surplus_account_id' => $surplus, 'reason' => 'Not ours'],
            ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
