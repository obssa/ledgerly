<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Module 10: the profit and loss with QuickBooks' "Display columns by": a column per period (months, quarters or years,
 * whose first days the page sends in the company's own calendar) or per customer or supplier, location, class or
 * project, each with the same accounts and totals as the one-column report. From the ledger lines on the accrual
 * basis; year-end closing entries (and their reversal) are left out, as in the profit and loss itself. Every choice
 * is a key from a fixed list; dates are bound, never SQL (CLAUDE.md rule 1).
 */
final class ProfitAndLossColumns
{
    public const BY = ['period', 'contact', 'location', 'class', 'project'];

    public const MAX_COLUMNS = 60;

    private const DIMENSIONS = ['location' => 'location_id', 'class' => 'class_id', 'project' => 'project_id'];

    /**
     * @param  list<string>  $starts  for "period": the first day of each column, in order, the first being $from (the page names them)
     * @return array{columns: list<array{key: string, label: string}>, sections: array<string, list<array<string, mixed>>>, totals: array<string, list<string>>}
     */
    public function run(string $companyId, string $from, string $to, string $by, array $starts = []): array
    {
        $by = in_array($by, self::BY, true) ? $by : throw new \InvalidArgumentException('by');
        $q = DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', fn ($j) => $j->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])
            ->whereIn('a.type', ['income', 'expense'])
            ->where('e.source_type', '<>', 'closing')
            ->whereNotExists(fn ($w) => $w->from('journal_entries as c')->whereColumn('c.company_id', 'e.company_id')->whereColumn('c.id', 'e.source_id')
                ->where('e.source_type', 'reversal')->where('c.source_type', 'closing'));

        // The column of a line: which period it falls in (how many of the first days are on or before it), or its
        // customer or supplier, or its tag. Fixed expressions; the dates are one bound array.
        if ($by === 'period') {
            $q->selectRaw('(SELECT count(*) FROM unnest(?::date[]) s WHERE s <= l.entry_date)::text AS col', ['{' . implode(',', $starts) . '}']);
        } elseif ($by === 'contact') {
            $q->selectRaw('l.contact_id::text AS col');
        } else {
            $q->selectRaw('l.' . self::DIMENSIONS[$by] . '::text AS col');   // a column from the fixed list above
        }
        $cells = $q->selectRaw("a.id::text AS account_id, a.code, a.name, a.type, a.subtype,
                sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END)::numeric(20,2)::text AS amount")
            ->groupBy('col', 'a.id', 'a.code', 'a.name', 'a.type', 'a.subtype')->orderBy('a.code')->limit(50000)->get();

        $columns = $this->columns($companyId, $by, $starts, $cells->pluck('col')->unique()->values()->all());
        if (count($columns) > self::MAX_COLUMNS) {
            throw new \LengthException('Too many columns');
        }
        $index = array_flip(array_column($columns, 'key'));

        $zero = fn () => array_fill(0, count($columns), BigDecimal::zero());
        $sections = ['income' => [], 'cost_of_sales' => [], 'expenses' => []];
        $totals = ['income' => $zero(), 'cost_of_sales' => $zero(), 'expenses' => $zero()];
        $accounts = [];
        foreach ($cells as $c) {
            $section = $c->type === 'income' ? 'income' : ($c->subtype === 'cogs' ? 'cost_of_sales' : 'expenses');
            $i = $index[$c->col ?? ''] ?? null;
            if ($i === null) {
                continue;   // not a column of this report (cannot happen within the company's own rows)
            }
            $accounts[$c->account_id] ??= ['section' => $section, 'account_id' => $c->account_id, 'code' => $c->code, 'name' => $c->name, 'values' => $zero()];
            $accounts[$c->account_id]['values'][$i] = $accounts[$c->account_id]['values'][$i]->plus($c->amount);
            $totals[$section][$i] = $totals[$section][$i]->plus($c->amount);
        }
        $str = fn (array $vs) => array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $vs);
        $sum = fn (array $vs) => (string) array_reduce($vs, fn (BigDecimal $t, BigDecimal $v) => $t->plus($v), BigDecimal::zero())->toScale(2);
        foreach ($accounts as $a) {
            $sections[$a['section']][] = ['account_id' => $a['account_id'], 'code' => $a['code'], 'name' => $a['name'], 'values' => $str($a['values']), 'total' => $sum($a['values'])];
        }
        $gross = array_map(fn (BigDecimal $i, BigDecimal $c) => $i->minus($c), $totals['income'], $totals['cost_of_sales']);
        $net = array_map(fn (BigDecimal $g, BigDecimal $x) => $g->minus($x), $gross, $totals['expenses']);
        $line = fn (array $vs) => ['values' => $str($vs), 'total' => $sum($vs)];

        return [
            'columns' => $columns,
            'sections' => $sections,
            'totals' => ['income' => $line($totals['income']), 'cost_of_sales' => $line($totals['cost_of_sales']), 'gross_profit' => $line($gross),
                'expenses' => $line($totals['expenses']), 'net_profit' => $line($net)],
        ];
    }

    /**
     * The columns in order: each period by its first day; or each customer, supplier or tag with something in
     * the period, by name, and "Not specified" for lines without one, last.
     *
     * @param  list<?string>  $used
     * @return list<array{key: string, label: string}>
     */
    private function columns(string $companyId, string $by, array $starts, array $used): array
    {
        if ($by === 'period') {
            return array_map(fn (int $i) => ['key' => (string) ($i + 1), 'label' => $starts[$i]], array_keys($starts));
        }
        $ids = array_values(array_filter($used, fn ($v) => $v !== null));
        $names = $by === 'contact'
            ? DB::table('contacts')->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')
            : DB::table('dimensions')->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id');
        $out = [];
        foreach ($names as $id => $name) {
            $out[] = ['key' => (string) $id, 'label' => (string) $name];
        }
        if (in_array(null, $used, true)) {
            $out[] = ['key' => '', 'label' => $by === 'contact' ? 'No customer or supplier' : 'Not specified'];
        }

        return $out;
    }
}
