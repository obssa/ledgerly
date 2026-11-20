<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9c (091): impairment. The laptop (36,000.00 over 36 months, 1,000.00 charged for December) is dropped and is
 * worth 24,000.00: an 11,000.00 impairment posts Dr depreciation and impairment, Cr accumulated depreciation; the
 * register shows 24,000.00; January's charge is what is left over the 35 months to go (685.71). Never below the
 * residual value; another company finds none of it.
 */
final class AssetImpairmentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_asset_is_written_down_and_depreciated_over_its_remaining_life(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $laptop = $c['fixed_asset'];
        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00', 'UTC'));
        $this->postJson('/api/v1/depreciation-runs', ['period_end' => '2026-12-31'], $h)->assertCreated()->assertJsonPath('total', '1000.00');

        $impair = fn (array $body) => $this->postJson("/api/v1/fixed-assets/{$laptop}/impair", [...['date' => '2027-01-05', 'reason' => 'Dropped; screen and board replaced'], ...$body], $h);
        $impair(['amount' => '35000.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');   // 35,000.00 left above a nil residual
        $impair(['amount' => '11000', 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $shown = $impair(['amount' => '11000'])->assertCreated()->assertJsonPath('impaired', '11000.00')->assertJsonPath('book_value', '24000.00')
            ->assertJsonPath('ahead.0', ['period_end' => '2027-01-31', 'amount' => '685.71'])->json();
        $entry = $shown['impairments'][0]['entry_id'];
        $lines = collect($this->getJson("/api/v1/journal-entries/{$entry}", $h)->assertOk()->assertJsonPath('source_type', 'asset_impairment')->json('lines'));
        $this->assertSame('11000.00', $lines->firstWhere('debit', '11000.00')['debit']);
        $this->getJson('/api/v1/fixed-assets', $h)->assertOk()->assertJsonFragment(['id' => $laptop, 'book_value' => '24000.00']);
        $this->getJson('/api/v1/depreciation/preview?period_end=2027-01-31', $h)->assertOk()->assertJsonPath('total', '685.71');
        $this->assertContains('asset_impaired', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $laptop])->pluck('action')->all());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson("/api/v1/fixed-assets/{$laptop}/impair", ['date' => '2027-01-05', 'amount' => '1', 'reason' => 'Not ours'], ['X-Company-Id' => $b['company']])->assertNotFound();
    }
}
