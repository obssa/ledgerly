<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Module 10, management reports: profit by location, class or project (from the ledger lines' dimensions), and sales
 * and purchases by customer or supplier, item or month (from posted documents, credit notes and supplier credits
 * taken off, in the base currency). Every grouping is one of a fixed list chosen in code; values are bound.
 */
final class AnalysisReports
{
    public const DIMENSIONS = ['location' => 'location_id', 'class' => 'class_id', 'project' => 'project_id'];

    public const GROUPS = ['contact', 'item', 'month'];

    private const POSTED = ['posted', 'part_paid', 'paid'];

    /**
     * Income, cost of sales, expenses and profit for each value of a dimension in a period, and for lines not tagged.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function profitByDimension(string $companyId, string $kind, string $from, string $to): array
    {
        $column = self::DIMENSIONS[$kind] ?? throw new \InvalidArgumentException('Unknown dimension');
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('dimensions as d', 'd.id', '=', "l.{$column}")
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])->whereIn('a.type', ['income', 'expense'])
            ->where('e.source_type', '<>', 'closing')
            ->whereNotExists(fn ($q) => $q->from('journal_entries as c')->whereColumn('c.company_id', 'e.company_id')->whereColumn('c.id', 'e.source_id')
                ->where('e.source_type', 'reversal')->where('c.source_type', 'closing'))
            ->groupBy('d.id', 'd.code', 'd.name')->orderByRaw('d.code NULLS LAST')
            ->select('d.id', 'd.code', 'd.name')
            ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE 0 END)::numeric(20,2)::text AS income")
            ->selectRaw("sum(CASE WHEN a.type = 'expense' AND a.subtype = 'cogs' THEN l.debit - l.credit ELSE 0 END)::numeric(20,2)::text AS cost_of_sales")
            ->selectRaw("sum(CASE WHEN a.type = 'expense' AND coalesce(a.subtype, '') <> 'cogs' THEN l.debit - l.credit ELSE 0 END)::numeric(20,2)::text AS expenses")
            ->selectRaw('sum(l.credit - l.debit)::numeric(20,2)::text AS profit')
            ->get();
        $out = $rows->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->id ? $r->name : 'Not tagged', 'income' => $r->income,
            'cost_of_sales' => $r->cost_of_sales, 'expenses' => $r->expenses, 'profit' => $r->profit,
            'margin' => BigDecimal::of($r->income)->isZero() ? null : (string) BigDecimal::of($r->profit)->multipliedBy(100)->dividedBy($r->income, 1, \App\Domain\Ledger\Rounding::halfUp())])->values()->all();

        return ['rows' => $out, 'totals' => $this->sum($out, ['income', 'cost_of_sales', 'expenses', 'profit'])];
    }

    /**
     * Sales (invoices and cash sales, credit notes taken off) or purchases (bills and expenses, supplier credits taken
     * off) in a period, by customer or supplier, item, or month: amount before tax, tax, and the number of documents.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function documents(string $companyId, string $side, string $group, string $from, string $to): array
    {
        $types = $side === 'sales' ? ['invoice' => 1, 'sales_receipt' => 1, 'credit_note' => -1] : ['bill' => 1, 'expense' => 1, 'vendor_credit' => -1];
        $sign = 'CASE d.type ' . implode(' ', array_map(fn ($t, $s) => "WHEN '{$t}' THEN {$s}", array_keys($types), $types)) . ' END';   // fixed names, not input
        $query = DB::table('document_lines as dl')
            ->join('documents as d', fn ($j) => $j->on('d.company_id', '=', 'dl.company_id')->on('d.id', '=', 'dl.document_id'))
            ->where('dl.company_id', $companyId)->whereIn('d.type', array_keys($types))->whereIn('d.status', self::POSTED)
            ->whereBetween('d.issue_date', [$from, $to]);
        [$key, $label] = match ($group) {
            'contact' => ['c.id', 'c.name'],
            'item' => ['i.id', 'i.name'],
            default => ["to_char(d.issue_date, 'YYYY-MM')", "to_char(d.issue_date, 'YYYY-MM')"],
        };
        if ($group === 'contact') {
            $query->join('contacts as c', 'c.id', '=', 'd.contact_id')->groupBy('c.id', 'c.name')->orderBy('c.name');
        } elseif ($group === 'item') {
            $query->leftJoin('items as i', 'i.id', '=', 'dl.item_id')->groupBy('i.id', 'i.name')->orderByRaw('i.name NULLS LAST');
        } else {
            $query->groupByRaw("to_char(d.issue_date, 'YYYY-MM')")->orderByRaw("to_char(d.issue_date, 'YYYY-MM')");
        }
        $rows = $query->selectRaw("{$key} AS key, {$label} AS label")
            ->selectRaw("sum(({$sign}) * round(dl.line_subtotal * d.fx_rate, 2))::numeric(20,2)::text AS amount")
            ->selectRaw("sum(({$sign}) * round(dl.line_tax * d.fx_rate, 2))::numeric(20,2)::text AS tax")
            ->selectRaw('count(DISTINCT d.id) AS documents')
            ->selectRaw($group === 'item' ? 'sum(dl.quantity)::numeric(20,2)::text AS quantity' : 'NULL AS quantity')
            ->limit(2000)->get();
        $out = $rows->map(fn ($r) => ['key' => $r->key, 'label' => $r->label ?? ($group === 'item' ? 'No item (described lines)' : '—'), 'amount' => $r->amount,
            'tax' => $r->tax, 'documents' => (int) $r->documents, 'quantity' => $r->quantity])->values()->all();
        $totals = $this->sum($out, ['amount', 'tax']);
        $grand = BigDecimal::of($totals['amount']);
        foreach ($out as &$row) {
            $row['share'] = $grand->isZero() ? null : (string) BigDecimal::of($row['amount'])->multipliedBy(100)->dividedBy($grand, 1, \App\Domain\Ledger\Rounding::halfUp());
        }

        return ['rows' => $out, 'totals' => $totals];
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $columns @return array<string, string> */
    private function sum(array $rows, array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            $out[$c] = (string) array_reduce($rows, fn (BigDecimal $t, array $r) => $t->plus($r[$c]), BigDecimal::zero())->toScale(2);
        }

        return $out;
    }
}
