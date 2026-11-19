<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: the 13-week cash forecast. It starts from what the bank, cash and wallet accounts hold, adds what customers
 * owe by due date and takes off what is owed to suppliers by due date (overdue in the first week), week by week, to a
 * closing balance; it says when cash would go below zero. Another company's documents never count.
 */
final class CashForecastTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_the_forecast_runs_from_the_cash_held_through_what_is_due(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $forecast = fn (array $hh = []) => $this->getJson('/api/v1/reports/cash-forecast', $hh ?: $h)->assertOk()->json();

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirsBefore = $forecast(['X-Company-Id' => $b['company']]);
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $f = $forecast();
        $this->assertCount(13, $f['data']);
        $this->assertSame($f['as_of'], $f['data'][0]['from']);
        $held = (string) $this->owner()->table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c['company'])->whereIn('a.subtype', ['bank', 'cash', 'mobile_money'])
            ->selectRaw('coalesce(sum(l.debit - l.credit), 0)::numeric(20,2)::text AS b')->value('b');
        $this->assertSame($held, $f['opening']);
        $net = array_reduce($f['data'], fn (string $s, array $w) => bcadd($s, $w['net'], 2), '0.00');
        $this->assertSame(bcadd($f['opening'], $net, 2), $f['closing']);
        $due = (string) $this->owner()->table('documents')->where('company_id', $c['company'])->where('type', 'invoice')->whereIn('status', ['posted', 'part_paid'])
            ->selectRaw('coalesce(sum(round(amount_due * fx_rate, 2)), 0)::numeric(20,2)::text AS d')->value('d');
        $this->assertSame($due, array_reduce($f['data'], fn (string $s, array $w) => bcadd($s, $w['receivables'], 2), '0.00'));

        // A bill of 25,000.00 due in ten days: the second week pays it, and cash goes below zero from then on.
        $dueOn = (new \DateTimeImmutable($f['as_of']))->modify('+10 days')->format('Y-m-d');
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => $f['as_of'], 'due_date' => $dueOn, 'currency' => 'ETB', 'reference' => 'BIG-1',
            'lines' => [['description' => 'Roaster service', 'quantity' => '1', 'unit_price' => '25000', 'account_id' => $c['rent']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $after = $forecast();
        $this->assertSame(bcadd($f['data'][1]['payables'], '25000.00', 2), $after['data'][1]['payables']);
        $this->assertSame(bcsub($f['closing'], '25000.00', 2), $after['closing']);
        if (bccomp($after['data'][1]['closing'], '0', 2) < 0) {
            $this->assertTrue($after['goes_below_zero']);
            $this->assertSame($after['data'][1]['closing'], $after['lowest']['closing']);
        }

        $this->getJson('/api/v1/reports/cash-forecast?weeks=30', $h)->assertUnprocessable();
        $this->assertCount(4, $this->getJson('/api/v1/reports/cash-forecast?weeks=4', $h)->assertOk()->json('data'));

        // Another company's forecast does not see the bill.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame($theirsBefore['data'], $forecast(['X-Company-Id' => $b['company']])['data']);
    }
}
