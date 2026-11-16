<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Ledger\Money;
use Illuminate\Support\Facades\DB;

/**
 * Ledger → trial balance → financial statements.
 *
 * Every statement is built from the trial_balance() database function, which reads whole months from
 * account_balances (kept in step with each posting) and only the days of a part month from the ledger
 * lines. A report therefore costs about the same for a company with a thousand lines or a hundred million,
 * and works for any date range in either calendar. Amounts stay decimal strings end to end (no floats).
 */
final class ReportService
{
    /** @return list<array{account_id: string, code: string, name: string, type: string, debit: string, credit: string}> */
    public function trialBalance(string $companyId, string $asOf): array
    {
        return DB::select(<<<'SQL'
            SELECT a.id AS account_id, a.code, a.name, a.type,
                   greatest(t.debit - t.credit, 0)::numeric(20,2)::text AS debit,
                   greatest(t.credit - t.debit, 0)::numeric(20,2)::text AS credit
              FROM trial_balance(?::uuid, ?::date) t
              JOIN accounts a ON a.id = t.account_id
             WHERE t.debit <> t.credit
             ORDER BY a.code
            SQL, [$companyId, $asOf]);
    }

    /**
     * Profit and loss for a period, optionally compared with another period.
     *
     * @return array{sections: array<string, list<array{code: string, name: string, current: string, previous: ?string}>>, totals: array<string, array{current: string, previous: ?string}>}
     */
    public function profitAndLoss(string $companyId, string $currency, string $from, string $to, ?string $compareFrom = null, ?string $compareTo = null): array
    {
        $current = $this->incomeStatementRows($companyId, $from, $to);
        $previous = $compareFrom ? $this->incomeStatementRows($companyId, $compareFrom, $compareTo) : [];

        $sections = ['income' => [], 'cost_of_sales' => [], 'expenses' => []];
        $totals = [];
        foreach (array_keys($sections) as $s) {
            $totals[$s] = ['current' => Money::zero($currency), 'previous' => Money::zero($currency)];
        }

        foreach (array_unique(array_merge(array_keys($current), array_keys($previous))) as $code) {
            $row = $current[$code] ?? $previous[$code];
            $section = $row->type === 'income' ? 'income' : ($row->subtype === 'cogs' ? 'cost_of_sales' : 'expenses');
            $cur = Money::of($current[$code]->amount ?? '0', $currency);
            $prev = Money::of($previous[$code]->amount ?? '0', $currency);

            $sections[$section][] = [
                'code' => $code,
                'name' => $row->name,
                'current' => $cur->toDecimalString(),
                'previous' => $compareFrom ? $prev->toDecimalString() : null,
            ];
            $totals[$section]['current'] = $totals[$section]['current']->plus($cur);
            $totals[$section]['previous'] = $totals[$section]['previous']->plus($prev);
        }

        $gross = fn (string $k) => $totals['income'][$k]->minus($totals['cost_of_sales'][$k]);
        $net = fn (string $k) => $gross($k)->minus($totals['expenses'][$k]);
        $out = fn (callable|array $v) => [
            'current' => (is_callable($v) ? $v('current') : $v['current'])->toDecimalString(),
            'previous' => $compareFrom ? (is_callable($v) ? $v('previous') : $v['previous'])->toDecimalString() : null,
        ];

        return [
            'sections' => $sections,
            'totals' => [
                'income' => $out($totals['income']),
                'cost_of_sales' => $out($totals['cost_of_sales']),
                'gross_profit' => $out($gross),
                'expenses' => $out($totals['expenses']),
                'net_profit' => $out($net),
            ],
        ];
    }

    /**
     * Balance sheet as at a date, with the accounting equation checked.
     *
     * @return array{sections: array<string, list<array{code: string, name: string, amount: string}>>, equation: array<string, mixed>}
     */
    public function balanceSheet(string $companyId, string $currency, string $asOf): array
    {
        // Each account's balance in its natural sign: assets debit-positive, the rest credit-positive.
        $rows = DB::select(<<<'SQL'
            SELECT a.code, a.name, a.type,
                   (CASE WHEN a.type IN ('asset', 'expense') THEN t.debit - t.credit ELSE t.credit - t.debit END)::numeric(20,2)::text AS amount
              FROM trial_balance(?::uuid, ?::date) t
              JOIN accounts a ON a.id = t.account_id
             ORDER BY a.code
            SQL, [$companyId, $asOf]);

        $sections = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $sum = ['asset' => Money::zero($currency), 'liability' => Money::zero($currency), 'equity' => Money::zero($currency), 'income' => Money::zero($currency), 'expense' => Money::zero($currency)];

        foreach ($rows as $row) {
            $amount = Money::of($row->amount, $currency);
            $sum[$row->type] = $sum[$row->type]->plus($amount);

            $key = match ($row->type) { 'asset' => 'assets', 'liability' => 'liabilities', 'equity' => 'equity', default => null };
            if ($key !== null && ! $amount->isZero()) {
                $sections[$key][] = ['code' => $row->code, 'name' => $row->name, 'amount' => $amount->toDecimalString()];
            }
        }

        $equation = new AccountingEquation($sum['asset'], $sum['liability'], $sum['equity'], $sum['income']->minus($sum['expense']));

        return ['sections' => $sections, 'equation' => $equation->toArray()];
    }

    /**
     * Bin card (stock card) for one item in one warehouse: every movement with running quantity and value.
     *
     * @return array{opening: array{quantity: string, value: string}, moves: list<object>}
     */
    public function binCard(string $companyId, string $itemId, string $warehouseId, string $from, string $to): array
    {
        $opening = DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(quantity), 0)::numeric(18,2)::text AS quantity,
                   coalesce(sum(amount), 0)::numeric(20,2)::text AS value
              FROM stock_moves
             WHERE company_id = ? AND item_id = ? AND warehouse_id = ? AND moved_on < ?
            SQL, [$companyId, $itemId, $warehouseId, $from]);

        $moves = DB::select(<<<'SQL'
            SELECT moved_on, kind, reference,
                   greatest(quantity, 0)::numeric(18,2)::text            AS qty_in,
                   greatest(-quantity, 0)::numeric(18,2)::text           AS qty_out,
                   round(unit_cost, 2)::text                             AS unit_cost,
                   (?::numeric + sum(quantity) OVER w)::numeric(18,2)::text            AS balance_qty,
                   amount::text                                          AS value,
                   (?::numeric + sum(amount) OVER w)::numeric(20,2)::text AS balance_value
              FROM stock_moves
             WHERE company_id = ? AND item_id = ? AND warehouse_id = ? AND moved_on BETWEEN ? AND ?
            WINDOW w AS (ORDER BY moved_on, seq ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
             ORDER BY moved_on, seq
            SQL, [$opening->quantity, $opening->value, $companyId, $itemId, $warehouseId, $from, $to]);

        return ['opening' => (array) $opening, 'moves' => $moves];
    }

    /** @return array<string, object{name: string, type: string, subtype: ?string, amount: string}> keyed by account code */
    private function incomeStatementRows(string $companyId, string $from, string $to): array
    {
        // Movement in a period = trial balance at its end minus trial balance the day before it starts.
        // Income shown as credit - debit; expenses as debit - credit: both positive in normal use.
        $rows = DB::select(<<<'SQL'
            WITH movement AS (
                SELECT account_id, sum(debit) AS debit, sum(credit) AS credit
                  FROM (SELECT account_id, debit, credit FROM trial_balance(?::uuid, ?::date)
                        UNION ALL
                        SELECT account_id, -debit, -credit FROM trial_balance(?::uuid, (?::date - 1))) both_ends
                 GROUP BY account_id
            )
            SELECT a.code, a.name, a.type, a.subtype,
                   (CASE WHEN a.type = 'income' THEN m.credit - m.debit ELSE m.debit - m.credit END)::numeric(20,2)::text AS amount
              FROM movement m
              JOIN accounts a ON a.id = m.account_id
             WHERE a.type IN ('income', 'expense') AND (m.debit <> 0 OR m.credit <> 0)
             ORDER BY a.code
            SQL, [$companyId, $to, $companyId, $from]);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->code] = $row;
        }

        return $keyed;
    }
}
