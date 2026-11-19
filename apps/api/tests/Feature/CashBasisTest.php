<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the profit and loss on the cash basis. On one day an invoice of 1,000.00 is posted and 400.00 of it is
 * paid, and 115.00 of fuel is paid from the bank. On the accrual basis the day shows 1,000.00 of income; on the cash
 * basis it shows the 400.00 received (the paid share of the invoice's income) and the 115.00 spent.
 */
final class CashBasisTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_cash_basis_counts_what_was_received_and_paid(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');   // the company's today
        $pl = fn (string $basis) => $this->getJson("/api/v1/reports/profit-and-loss?from={$today}&to={$today}&basis={$basis}", $h)->assertOk()->json();
        $row = fn (array $report, string $section, string $code) => collect($report['sections'][$section])->firstWhere('code', $code)['current'] ?? '0.00';
        $code = fn (string $id) => (string) $this->owner()->table('accounts')->where('id', $id)->value('code');

        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting service', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();
        $this->postJson('/api/v1/payments-received', ['contact_id' => $c['contact'], 'account_id' => $c['bank'], 'payment_date' => $today, 'method' => 'bank_transfer',
            'currency' => 'ETB', 'amount' => '400', 'allocations' => [['invoice_id' => $invoice, 'amount' => '400']]], $h)->assertCreated();
        $this->postJson('/api/v1/expenses', ['contact_id' => $c['supplier'], 'paid_from_account_id' => $c['bank'], 'payment_method' => 'cash', 'issue_date' => $today,
            'currency' => 'ETB', 'lines' => [['description' => 'Fuel', 'quantity' => '1', 'unit_price' => '115', 'account_id' => $c['rent']]]], $h)->assertCreated();

        $accrual = $pl('accrual');
        $cash = $pl('cash');
        $this->assertSame(['accrual', 'cash'], [$accrual['basis'], $cash['basis']]);
        $this->assertSame('1000.00', $row($accrual, 'income', $code($c['sales'])));
        $this->assertSame('400.00', $row($cash, 'income', $code($c['sales'])));
        $this->assertSame('115.00', $row($cash, 'expenses', $code($c['rent'])));
        $this->assertSame('285.00', $cash['totals']['net_profit']['current']);

        // A comparison period is read on the same basis; an unknown basis is refused.
        $this->getJson("/api/v1/reports/profit-and-loss?from={$today}&to={$today}&basis=cash&compare_from={$today}&compare_to={$today}", $h)
            ->assertOk()->assertJsonPath('totals.net_profit.previous', '285.00');
        $this->getJson("/api/v1/reports/profit-and-loss?from={$today}&to={$today}&basis=accrued", $h)->assertUnprocessable();
    }
}
