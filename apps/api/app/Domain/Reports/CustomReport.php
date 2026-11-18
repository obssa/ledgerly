<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Module 10: a report put together by the person reading it, from the ledger lines of a period. Rows are one grouping
 * (account, account type, customer or supplier, project, location or class); columns are the whole period, or each month
 * or quarter; the figure is the amount by each account's own side, or debits, credits or debits less credits; filters by
 * account type and dimension. Every choice is one of a fixed list chosen in code, never SQL from the request; values are
 * bound. Year-end closing entries (and their reversal) are left out, as in the other management reports.
 */
final class CustomReport
{
    public const ROWS = ['account', 'account_type', 'contact', 'project', 'location', 'class'];

    public const COLUMNS = ['total', 'month', 'quarter'];

    public const MEASURES = ['natural', 'debit', 'credit', 'net'];

    public const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    private const DIMENSIONS = ['project' => 'project_id', 'location' => 'location_id', 'class' => 'class_id'];

    /**
     * @param array{rows: string, columns: string, measure: string, account_type?: ?string, project_id?: ?string, location_id?: ?string, class_id?: ?string} $o
     * @return array{columns: list<string>, rows: list<array{key: ?string, code: ?string, label: string, values: list<string>, total: string}>, totals: list<string>, total: string}
     */
    public function run(string $companyId, string $from, string $to, array $o): array
    {
        $rows = in_array($o['rows'], self::ROWS, true) ? $o['rows'] : throw new \InvalidArgumentException('rows');
        $columns = in_array($o['columns'], self::COLUMNS, true) ? $o['columns'] : throw new \InvalidArgumentException('columns');
        $measure = in_array($o['measure'], self::MEASURES, true) ? $o['measure'] : throw new \InvalidArgumentException('measure');

        // Fixed expressions, picked by the choices above (never text from the request).
        $figure = match ($measure) {
            'debit' => 'l.debit',
            'credit' => 'l.credit',
            'net' => 'l.debit - l.credit',
            default => "CASE WHEN a.type IN ('asset', 'expense') THEN l.debit - l.credit ELSE l.credit - l.debit END",
        };
        $period = match ($columns) {
            'month' => "to_char(l.entry_date, 'YYYY-MM')",
            'quarter' => "to_char(l.entry_date, 'YYYY') || '-Q' || to_char(l.entry_date, 'Q')",
            default => "'total'",
        };

        $q = DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])
            ->where('e.source_type', '<>', 'closing')
            ->whereNotExists(fn ($w) => $w->from('journal_entries as c')->whereColumn('c.company_id', 'e.company_id')->whereColumn('c.id', 'e.source_id')
                ->where('e.source_type', 'reversal')->where('c.source_type', 'closing'))
            ->when($o['account_type'] ?? null, fn ($b, $t) => $b->where('a.type', $t));
        foreach (self::DIMENSIONS as $name => $column) {
            if (! empty($o["{$name}_id"])) {
                $q->where("l.{$column}", $o["{$name}_id"]);
            }
        }

        [$key, $code, $label, $order] = match ($rows) {
            'account' => ['a.id', 'a.code', 'a.name', 'a.code'],
            'account_type' => ['a.type', 'NULL::text', 'a.type', "array_position(ARRAY['asset','liability','equity','income','expense'], a.type::text)::text"],
            'contact' => ['k.id', 'NULL::text', 'k.name', 'k.name'],
            default => ['d.id', 'd.code', 'd.name', 'd.code'],
        };
        if ($rows === 'contact') {
            $q->leftJoin('contacts as k', 'k.id', '=', 'l.contact_id');
        } elseif (isset(self::DIMENSIONS[$rows])) {
            $q->leftJoin('dimensions as d', 'd.id', '=', 'l.' . self::DIMENSIONS[$rows]);
        }

        // Grouped by the row's columns and the period; the fixed placeholders ('total', NULL) are not grouped by.
        $group = implode(', ', array_values(array_unique(array_filter([$key, $code, $label, $period], fn ($e) => ! in_array($e, ["'total'", 'NULL::text'], true)))));
        $cells = $q->groupByRaw($group)
            ->selectRaw("{$key}::text AS key, {$code} AS code, {$label} AS label, {$period} AS period, sum({$figure})::numeric(20,2)::text AS amount, min({$order}) AS sort")
            ->orderByRaw('sort NULLS LAST')->limit(20000)->get();

        $periods = $columns === 'total' ? ['total'] : $this->periods($from, $to, $columns);
        $byRow = [];
        foreach ($cells as $c) {
            $id = $c->key ?? '';
            $byRow[$id] ??= ['key' => $c->key, 'code' => $c->code, 'label' => $c->key === null ? $this->untagged($rows) : (string) $c->label, 'cells' => []];
            $byRow[$id]['cells'][$c->period] = BigDecimal::of($byRow[$id]['cells'][$c->period] ?? '0')->plus($c->amount);
        }

        $out = [];
        $totals = array_fill(0, count($periods), BigDecimal::zero());
        $grand = BigDecimal::zero();
        foreach ($byRow as $r) {
            $values = [];
            $sum = BigDecimal::zero();
            foreach ($periods as $i => $p) {
                $v = $r['cells'][$p] ?? BigDecimal::zero();
                $values[] = (string) $v->toScale(2);
                $sum = $sum->plus($v);
                $totals[$i] = $totals[$i]->plus($v);
            }
            if ($sum->isZero() && array_filter($values, fn ($v) => $v !== '0.00') === []) {
                continue;   // nothing to show
            }
            $grand = $grand->plus($sum);
            $out[] = ['key' => $r['key'], 'code' => $r['code'], 'label' => $r['label'], 'values' => $values, 'total' => (string) $sum->toScale(2)];
        }

        return [
            'columns' => $columns === 'total' ? ['Total'] : $periods,
            'rows' => $out,
            'totals' => array_map(fn (BigDecimal $t) => (string) $t->toScale(2), $totals),
            'total' => (string) $grand->toScale(2),
        ];
    }

    /** @return list<string> every month ("2026-11") or quarter ("2026-Q4") of the period, so empty ones still show */
    private function periods(string $from, string $to, string $columns): array
    {
        $out = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end && count($out) < 120) {
            $label = $columns === 'month' ? $cursor->format('Y-m') : $cursor->format('Y') . '-Q' . (int) ceil((int) $cursor->format('n') / 3);
            if (! in_array($label, $out, true)) {
                $out[] = $label;
            }
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    private function untagged(string $rows): string
    {
        return match ($rows) {
            'contact' => 'No customer or supplier',
            'project', 'location', 'class' => 'Not tagged',
            default => 'Other',
        };
    }
}
