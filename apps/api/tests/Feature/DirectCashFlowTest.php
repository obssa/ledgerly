<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 10: the cash flow statement by the direct method. In late November the bank received 10,000.00 for sales and a
 * 50,000.00 loan, and paid 3,000.00 rent, 500.00 VAT and 20,000.00 for a machine; 1,000.00 went from the bank to the
 * cash box. The statement shows each by what it was for, the transfer cancels out, and the total is the change in cash.
 */
final class DirectCashFlowTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_cash_received_and_paid_is_shown_by_what_it_was_for(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $code = fn (string $code) => (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => $code])->value('id');
        [$bank, $box, $ppe, $loan, $rent, $vat] = [$code('1100'), $code('1000'), $code('1500'), $code('2500'), $code('6800'), $code('2300')];
        $post = fn (string $memo, string $dr, string $cr, string $amount) => $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-12', 'memo' => $memo,
            'lines' => [['account_id' => $dr, 'debit' => $amount], ['account_id' => $cr, 'credit' => $amount]]], $h)->assertCreated();
        $post('Sales banked', $bank, $c['sales'], '10000');
        $post('Loan from the bank', $bank, $loan, '50000');
        $post('Rent', $rent, $bank, '3000');
        $post('VAT paid', $vat, $bank, '500');
        $post('Roaster bought', $ppe, $bank, '20000');
        $post('Float for the cash box', $box, $bank, '1000');

        $s = $this->getJson('/api/v1/reports/cash-flow?from=2026-11-10&to=2026-11-30&method=direct', $h)->assertOk()
            ->assertJsonPath('method', 'direct')->assertJsonPath('balanced', true)->json();
        $line = fn (string $key) => collect($s['lines'])->firstWhere('key', $key)['amount'];
        $this->assertSame(['10000.00', '-3000.00', '-500.00', '-20000.00', '50000.00', '0.00'],
            [$line('customers'), $line('suppliers'), $line('taxes'), $line('investing'), $line('financing'), $line('employees')]);
        $this->assertSame(['6500.00', '-20000.00', '50000.00', '36500.00', '36500.00'],
            [$s['totals']['operating'], $s['totals']['investing'], $s['totals']['financing'], $s['net_change'], $s['cash_change']]);
        $this->assertSame(bcadd($s['opening_cash'], '36500.00', 2), $s['closing_cash']);

        // The indirect statement for the same days comes to the same change in cash.
        $this->getJson('/api/v1/reports/cash-flow?from=2026-11-10&to=2026-11-30', $h)->assertOk()->assertJsonPath('net_change', '36500.00')
            ->assertJsonPath('balanced', true);
        $this->getJson('/api/v1/reports/cash-flow?from=2026-11-10&to=2026-11-30&method=sideways', $h)->assertUnprocessable();
    }
}
