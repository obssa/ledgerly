<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ledger\RecurringJournals;
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

    /**
     * Recurring entries count on their dates: a bank-paid lease of 2,000.00 three days from now and a month later goes
     * out in those weeks; one that never touches cash moves nothing; a one-off repeat of the coffee invoice tomorrow
     * comes in by the customer's terms after it, at the invoice's own total. Another company sees none of it.
     */
    public function test_recurring_journals_and_invoices_count_on_their_dates(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $forecast = fn (array $hh = []) => $this->getJson('/api/v1/reports/cash-forecast', $hh ?: $h)->assertOk()->json();
        $sum = fn (array $f, string $key) => array_reduce($f['data'], fn (string $s, array $w) => bcadd($s, $w[$key], 2), '0.00');

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirsBefore = $forecast(['X-Company-Id' => $b['company']]);
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $f = $forecast();
        $on = fn (int $days) => (new \DateTimeImmutable($f['as_of']))->modify("+{$days} days")->format('Y-m-d');
        $weekOf = fn (string $date) => intdiv((int) (new \DateTimeImmutable($f['as_of']))->diff(new \DateTimeImmutable($date))->format('%a'), 7);

        $lease = fn (string $credit, string $amount) => $this->postJson('/api/v1/recurring-journals', ['name' => "Lease {$amount}", 'memo' => 'Generator lease',
            'frequency' => 'monthly', 'next_on' => $on(3), 'ends_on' => $on(40),
            'lines' => [['account_id' => $c['rent'], 'debit' => $amount], ['account_id' => $credit, 'credit' => $amount]]], $h)->assertCreated();
        $lease($c['bank'], '2000');
        $lease($c['equity'], '500');   // never touches cash
        $after = $forecast();
        $second = $weekOf(RecurringJournals::advance($on(3), 'monthly'));
        $this->assertSame(bcadd($f['data'][0]['recurring_out'], '2000.00', 2), $after['data'][0]['recurring_out']);
        $this->assertSame(bcadd($f['data'][$second]['recurring_out'], '2000.00', 2), $after['data'][$second]['recurring_out']);
        $this->assertSame(bcadd($sum($f, 'recurring_out'), '4000.00', 2), $sum($after, 'recurring_out'));
        $this->assertSame(bcsub($f['closing'], '4000.00', 2), $after['closing']);

        $source = $this->getJson("/api/v1/invoices/{$c['invoice']}", $h)->assertOk()->json();
        $this->postJson("/api/v1/invoices/{$c['invoice']}/repeat", ['name' => 'Coffee once more', 'frequency' => 'monthly', 'next_on' => $on(1), 'ends_on' => $on(1)], $h)->assertCreated();
        $terms = (int) $this->owner()->table('contacts')->where('id', $c['contact'])->value('payment_terms_days');
        $week = $weekOf($on(1 + $terms));
        $then = $forecast();
        $this->assertSame(bcadd($after['data'][$week]['recurring_in'], $source['total'], 2), $then['data'][$week]['recurring_in']);
        $this->assertSame(bcadd($sum($after, 'recurring_in'), $source['total'], 2), $sum($then, 'recurring_in'));
        $this->assertSame(bcadd($after['closing'], $source['total'], 2), $then['closing']);

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame($theirsBefore['data'], $forecast(['X-Company-Id' => $b['company']])['data']);
    }

    /**
     * A bill of 25,000.00 due in ten days with 10,000.00 of it scheduled for payment in twenty: that part leaves the
     * bill's week and goes out on the planned day, so the closing cash is the same and nothing is counted twice. Planned
     * beyond the window, it is not in the forecast; cancelled, the bill counts by its due date again.
     */
    public function test_a_scheduled_payment_moves_its_bill_to_the_planned_day(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $forecast = fn (array $hh = []) => $this->getJson('/api/v1/reports/cash-forecast', $hh ?: $h)->assertOk()->json();
        $sum = fn (array $f, string $key) => array_reduce($f['data'], fn (string $s, array $w) => bcadd($s, $w[$key], 2), '0.00');

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirsBefore = $forecast(['X-Company-Id' => $b['company']]);
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $f = $forecast();
        $on = fn (int $days) => (new \DateTimeImmutable($f['as_of']))->modify("+{$days} days")->format('Y-m-d');
        $weekOf = fn (string $date) => intdiv((int) (new \DateTimeImmutable($f['as_of']))->diff(new \DateTimeImmutable($date))->format('%a'), 7);

        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => $f['as_of'], 'due_date' => $on(10), 'currency' => 'ETB', 'reference' => 'SCH-1',
            'lines' => [['description' => 'Roaster service', 'quantity' => '1', 'unit_price' => '25000', 'account_id' => $c['rent']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $h)->assertOk();
        $billed = $forecast();
        $due = $weekOf($on(10));
        $this->assertSame(bcadd($f['data'][$due]['payables'], '25000.00', 2), $billed['data'][$due]['payables']);

        $plan = fn (string $payOn) => $this->postJson('/api/v1/scheduled-payments', ['contact_id' => $c['supplier'], 'account_id' => $c['bank'], 'currency' => 'ETB',
            'pay_on' => $payOn, 'method' => 'bank_transfer', 'bills' => [['bill_id' => $bill, 'amount' => '10000']]], $h)->assertCreated()->json('id');
        $id = $plan($on(20));
        $s = $forecast();
        $this->assertSame(bcsub($billed['data'][$due]['payables'], '10000.00', 2), $s['data'][$due]['payables']);
        $this->assertSame('10000.00', $s['data'][$weekOf($on(20))]['scheduled_out']);
        $this->assertSame('10000.00', $sum($s, 'scheduled_out'));
        $this->assertSame($billed['closing'], $s['closing'], 'moved, not counted twice');

        // Cancelled: the bill counts by its due date again. Planned after the window: that part is not in the forecast.
        $this->postJson("/api/v1/scheduled-payments/{$id}/cancel", ['reason' => 'Paying it next quarter'], $h)->assertOk();
        $this->assertSame($billed['data'], $forecast()['data']);
        $plan($on(200));
        $later = $forecast();
        $this->assertSame('0.00', $sum($later, 'scheduled_out'));
        $this->assertSame(bcadd($billed['closing'], '10000.00', 2), $later['closing']);

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame($theirsBefore['data'], $forecast(['X-Company-Id' => $b['company']])['data']);
    }

    public function test_confirmed_orders_count_for_what_is_not_yet_invoiced_or_billed(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $forecast = fn (array $hh = []) => $this->getJson('/api/v1/reports/cash-forecast', $hh ?: $h)->assertOk()->json();
        $sum = fn (array $f, string $key) => array_reduce($f['data'], fn (string $s, array $w) => bcadd($s, $w[$key], 2), '0.00');

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirsBefore = $forecast(['X-Company-Id' => $b['company']]);
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $f = $forecast();
        $on = fn (int $days) => (new \DateTimeImmutable($f['as_of']))->modify("+{$days} days")->format('Y-m-d');
        $weekOf = fn (string $date) => intdiv((int) (new \DateTimeImmutable($f['as_of']))->diff(new \DateTimeImmutable($date))->format('%a'), 7);
        $terms = fn (string $contact) => (int) $this->owner()->table('contacts')->where('id', $contact)->value('payment_terms_days');

        // A confirmed order for 10 bags, wanted in 10 days: its total comes in by the customer's terms after that.
        $so = $this->postJson('/api/v1/sales-orders', ['contact_id' => $c['contact'], 'issue_date' => $f['as_of'], 'due_date' => $on(10), 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee bags', 'quantity' => '10', 'unit_price' => '100', 'account_id' => $c['sales']]]], $h)->assertCreated()->json();
        $this->assertSame($f['data'], $forecast()['data'], 'a draft order is not counted');
        $this->postJson("/api/v1/sales-orders/{$so['id']}/accept", [], $h)->assertOk();
        $week = $weekOf($on(10 + $terms($c['contact'])));
        $after = $forecast();
        $this->assertSame(bcadd($f['data'][$week]['orders_in'], $so['total'], 2), $after['data'][$week]['orders_in']);
        $this->assertSame(bcadd($f['closing'], $so['total'], 2), $after['closing']);

        // Four bags invoiced and posted: they move from the order to what the customer owes, counted once.
        $invoice = $this->postJson("/api/v1/sales-orders/{$so['id']}/convert", ['to' => 'invoice', 'lines' => [['line_id' => $so['lines'][0]['id'], 'quantity' => '4']]], $h)
            ->assertCreated()->json('id');
        $this->assertSame($after['data'], $forecast()['data'], 'a draft invoice leaves the order as it was');
        $total = $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk()->json('total');
        $then = $forecast();
        $this->assertSame(bcsub(bcadd($sum($f, 'orders_in'), $so['total'], 2), $total, 2), $sum($then, 'orders_in'));
        $this->assertSame(bcadd($sum($f, 'receivables'), $total, 2), $sum($then, 'receivables'));
        $this->assertSame($after['closing'], $then['closing']);

        // An issued purchase order goes out by the supplier's terms after the date wanted.
        $po = $this->postJson('/api/v1/purchase-orders', ['contact_id' => $c['supplier'], 'issue_date' => $f['as_of'], 'due_date' => $on(7), 'currency' => 'ETB', 'reference' => 'PO-F1',
            'lines' => [['description' => 'Packaging', 'quantity' => '1', 'unit_price' => '600', 'account_id' => $c['rent']]]], $h)->assertCreated()->json();
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/issue", [], $h)->assertOk();
        $out = $forecast();
        $this->assertSame(bcadd($then['data'][$weekOf($on(7 + $terms($c['supplier'])))]['orders_out'], $po['total'], 2),
            $out['data'][$weekOf($on(7 + $terms($c['supplier'])))]['orders_out']);
        $this->assertSame(bcsub($then['closing'], $po['total'], 2), $out['closing']);

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame($theirsBefore['data'], $forecast(['X-Company-Id' => $b['company']])['data']);
    }
}
