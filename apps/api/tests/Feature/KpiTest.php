<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the home page's key figures agree with the books: cash is what the money accounts hold, receivable and
 * payable are their control accounts, overdue is what posted invoices still owe past due, and this month's sales and
 * net profit move with what is posted today. Another company's figures do not move.
 */
final class KpiTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_key_figures_agree_with_the_books(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $kpis = fn (array $hh = []) => $this->getJson('/api/v1/reports/kpis', $hh ?: $h)->assertOk()->json();
        $ledger = fn (?array $subtypes, ?string $key, bool $credit = false) => (string) $this->owner()->table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c['company'])
            ->when($subtypes, fn ($q) => $q->whereIn('a.subtype', $subtypes))->when($key, fn ($q) => $q->where('a.system_key', $key))
            ->selectRaw($credit ? 'coalesce(sum(l.credit - l.debit), 0)::numeric(20,2)::text AS b' : 'coalesce(sum(l.debit - l.credit), 0)::numeric(20,2)::text AS b')->value('b');

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $theirs = $kpis(['X-Company-Id' => $b['company']]);
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        $k = $kpis();
        $this->assertSame($ledger(['bank', 'cash', 'mobile_money'], null), $k['cash']['now']);
        $this->assertSame($ledger(null, 'accounts_receivable'), $k['receivable']['balance']);
        $this->assertSame($ledger(null, 'accounts_payable', true), $k['payable']['balance']);
        $overdue = $this->owner()->table('documents')->where(['company_id' => $c['company'], 'type' => 'invoice'])->whereIn('status', ['posted', 'part_paid'])
            ->where('amount_due', '>', 0)->where('due_date', '<', $k['as_of'])->selectRaw('count(*) AS n, coalesce(sum(round(amount_due * fx_rate, 2)), 0)::numeric(20,2)::text AS a')->first();
        $this->assertSame([(int) $overdue->n, (string) $overdue->a], [$k['receivable']['overdue_count'], $k['receivable']['overdue']]);

        // An invoice of 1,000.00 today: this month's sales and net profit, and what customers owe, rise by it.
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $k['as_of'], 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting service', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $after = $kpis();
        $this->assertSame(bcadd($k['month']['sales'], '1000.00', 2), $after['month']['sales']);
        $this->assertSame(bcadd($k['month']['net_profit'], '1000.00', 2), $after['month']['net_profit']);
        $this->assertSame(bcadd($k['receivable']['balance'], '1000.00', 2), $after['receivable']['balance']);
        $this->assertSame($k['month']['sales_last'], $after['month']['sales_last']);

        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->assertSame($theirs, $kpis(['X-Company-Id' => $b['company']]));
    }
}
