<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Ledger\Money;
use Illuminate\Support\Facades\DB;

/**
 * Financial statements read straight from journal_lines, so they always agree with the ledger.
 * Amounts stay decimal strings end to end (no floats).
 */
final class ReportService
{
    /** @return list<array{account_id: string, code: string, name: string, type: string, debit: string, credit: string}> */
    public function trialBalance(string $companyId, string $asOf): array
    {
        return DB::select(<<<'SQL'
            SELECT a.id AS account_id, a.code, a.name, a.type,
                   greatest(sum(l.debit) - sum(l.credit), 0)::numeric(20,2)::text AS debit,
                   greatest(sum(l.credit) - sum(l.debit), 0)::numeric(20,2)::text AS credit
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
              JOIN accounts a        ON a.id = l.account_id
             WHERE l.company_id = ? AND e.entry_date <= ?
             GROUP BY a.id, a.code, a.name, a.type
            HAVING sum(l.debit) <> sum(l.credit)
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
                   (CASE WHEN a.type IN ('asset', 'expense') THEN sum(l.debit - l.credit) ELSE sum(l.credit - l.debit) END)::numeric(20,2)::text AS amount
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
              JOIN accounts a        ON a.id = l.account_id
             WHERE l.company_id = ? AND e.entry_date <= ?
             GROUP BY a.code, a.name, a.type
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
                   coalesce(sum(quantity * unit_cost), 0)::numeric(20,2)::text AS value
              FROM stock_moves
             WHERE company_id = ? AND item_id = ? AND warehouse_id = ? AND moved_on < ?
            SQL, [$companyId, $itemId, $warehouseId, $from]);

        $moves = DB::select(<<<'SQL'
            SELECT moved_on, kind, reference,
                   greatest(quantity, 0)::numeric(18,2)::text            AS qty_in,
                   greatest(-quantity, 0)::numeric(18,2)::text           AS qty_out,
                   unit_cost::text                                       AS unit_cost,
                   (?::numeric + sum(quantity) OVER w)::numeric(18,2)::text            AS balance_qty,
                   (?::numeric + sum(quantity * unit_cost) OVER w)::numeric(20,2)::text AS balance_value
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
        // Income shown as credit - debit; expenses as debit - credit: both positive in normal use.
        $rows = DB::select(<<<'SQL'
            SELECT a.code, a.name, a.type, a.subtype,
                   (CASE WHEN a.type = 'income' THEN sum(l.credit - l.debit) ELSE sum(l.debit - l.credit) END)::numeric(20,2)::text AS amount
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
              JOIN accounts a        ON a.id = l.account_id
             WHERE l.company_id = ? AND e.entry_date BETWEEN ? AND ? AND a.type IN ('income', 'expense')
             GROUP BY a.code, a.name, a.type, a.subtype
             ORDER BY a.code
            SQL, [$companyId, $from, $to]);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->code] = $row;
        }

        return $keyed;
    }
}
