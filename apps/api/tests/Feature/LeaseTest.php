<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9 (095): leases. A roaster leased for 12 payments of 1,000.00 in arrears at 12% a year is a finance lease:
 * recognised at 11,255.08 (Dr the asset, registered at that cost; Cr the lease liability). The first payment is
 * 112.55 interest and 887.45 off the liability, the second 103.68 and 896.32, leaving 9,471.31 owed and 10,000.00 to
 * pay. The fixture's shop lease (operating, 100.00 a month) is an expense as it is paid, and takes nothing once ended.
 * Another company finds none of it.
 */
final class LeaseTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_finance_lease_is_recognised_and_paid_by_the_effective_interest_method(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00', 'UTC'));
        $fa = $this->owner()->table('fixed_assets')->where('id', $c['fixed_asset'])->first(['asset_account_id', 'depreciation_account_id', 'expense_account_id']);
        $liability = $this->postJson('/api/v1/accounts', ['code' => 'LL-1', 'name' => 'Lease liability', 'type' => 'liability'], $h)->assertCreated()->json('id');
        $interest = $this->postJson('/api/v1/accounts', ['code' => 'LI-1', 'name' => 'Lease interest', 'type' => 'expense'], $h)->assertCreated()->json('id');

        $lease = fn (array $body) => $this->postJson('/api/v1/leases', [...['code' => 'L-PRESS', 'name' => 'Roaster on lease', 'lessor_id' => $c['supplier'], 'kind' => 'finance',
            'starts_on' => '2026-11-01', 'months' => 12, 'payment' => '1000', 'annual_rate' => '0.12', 'liability_account_id' => $liability, 'interest_account_id' => $interest,
            'asset_account_id' => $fa->asset_account_id, 'depreciation_account_id' => $fa->depreciation_account_id, 'depreciation_expense_account_id' => $fa->expense_account_id], ...$body], $h);
        $lease(['annual_rate' => null])->assertUnprocessable()->assertJsonValidationErrors('annual_rate');
        $lease(['liability_account_id' => $interest])->assertUnprocessable()->assertJsonValidationErrors('liability_account_id');
        $lease(['code' => 'IT-001'])->assertUnprocessable()->assertJsonValidationErrors('code');   // the laptop's code
        $lease(['expense_account_id' => $c['rent']])->assertUnprocessable()->assertJsonValidationErrors('expense_account_id');

        $l = $lease([])->assertCreated()->assertJsonPath('present_value', '11255.08')->assertJsonPath('annual_rate', '0.12')
            ->assertJsonPath('schedule.0.due_on', '2026-11-30')->assertJsonPath('schedule.0.interest', '112.55')->assertJsonPath('schedule.0.principal', '887.45')
            ->assertJsonPath('schedule.11.owed', '0.00')->json();
        $opening = collect($this->getJson("/api/v1/journal-entries/{$l['commencement_entry_id']}", $h)->assertOk()->assertJsonPath('source_type', 'lease')->json('lines'));
        $this->assertSame('11255.08', $opening->firstWhere('account_id', $fa->asset_account_id)['debit']);
        $this->assertSame('11255.08', $opening->firstWhere('account_id', $liability)['credit']);
        $this->getJson("/api/v1/fixed-assets/{$l['fixed_asset_id']}", $h)->assertOk()->assertJsonPath('cost', '11255.08')->assertJsonPath('useful_life_months', 12);

        $pay = fn (string $id, string $date) => $this->postJson("/api/v1/leases/{$id}/payments", ['date' => $date, 'bank_account_id' => $c['bank']], $h);
        $first = $pay($l['id'], '2026-11-30')->assertCreated()->assertJsonPath('schedule.0.paid_on', '2026-11-30')->json();
        $lines = collect($this->getJson("/api/v1/journal-entries/{$first['schedule'][0]['entry_id']}", $h)->assertOk()->json('lines'));
        $this->assertSame(['887.45', '112.55', '1000.00'], [$lines->firstWhere('account_id', $liability)['debit'], $lines->firstWhere('account_id', $interest)['debit'],
            $lines->firstWhere('account_id', $c['bank'])['credit']]);
        $pay($l['id'], '2026-12-31')->assertCreated()->assertJsonPath('schedule.1.interest', '103.68')->assertJsonPath('schedule.1.principal', '896.32')
            ->assertJsonPath('schedule.1.owed', '9471.31');
        $register = collect($this->getJson('/api/v1/leases', $h)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([2, '9471.31'], [$register[$l['id']]['paid'], $register[$l['id']]['owed']]);
        $this->assertSame('10000.00', bcadd(bcadd($register[$l['id']]['to_pay']['within_year'], $register[$l['id']]['to_pay']['one_to_five'], 2), $register[$l['id']]['to_pay']['later'], 2));

        // The shop lease: an operating lease is an expense as it is paid; ended, it takes nothing more.
        $shop = $pay($c['lease'], '2026-11-30')->assertCreated()->assertJsonPath('schedule.0.interest', '0.00')->json();
        $this->assertSame('100.00', collect($this->getJson("/api/v1/journal-entries/{$shop['schedule'][0]['entry_id']}", $h)->json('lines'))->firstWhere('account_id', $c['rent'])['debit']);
        $this->postJson("/api/v1/leases/{$c['lease']}/end", [], $h)->assertOk()->assertJsonPath('status', 'ended');
        $pay($c['lease'], '2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame(3, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'lease_paid'])->count());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company']];
        $this->getJson("/api/v1/leases/{$l['id']}", $hb)->assertNotFound();
        $this->postJson("/api/v1/leases/{$l['id']}/payments", ['date' => '2027-01-05', 'bank_account_id' => $b['bank']], $hb)->assertNotFound();
        $this->assertNotContains($l['id'], collect($this->getJson('/api/v1/leases', $hb)->assertOk()->json('data'))->pluck('id')->all());
    }
}
