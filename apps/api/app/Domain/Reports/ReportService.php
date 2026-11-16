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
        // Movement in a period = trial balance at its end minus trial balance the day before it starts, leaving out the
        // year-end closing entries (and their reversals): a closed year still shows the profit it made (035).
        // Income shown as credit - debit; expenses as debit - credit: both positive in normal use.
        $rows = DB::select(<<<'SQL'
            WITH movement AS (
                SELECT account_id, sum(debit) AS debit, sum(credit) AS credit
                  FROM (SELECT account_id, debit, credit FROM trial_balance(?::uuid, ?::date)
                        UNION ALL
                        SELECT account_id, -debit, -credit FROM trial_balance(?::uuid, (?::date - 1))
                        UNION ALL
                        SELECT l.account_id, -l.debit, -l.credit
                          FROM journal_lines l
                          JOIN journal_entries e ON e.company_id = l.company_id AND e.id = l.journal_entry_id
                         WHERE l.company_id = ?::uuid AND l.entry_date BETWEEN ?::date AND ?::date
                           AND (e.source_type = 'closing'
                                OR (e.source_type = 'reversal' AND e.source_id IN (
                                    SELECT c.id FROM journal_entries c WHERE c.company_id = ?::uuid AND c.source_type = 'closing')))) both_ends
                 GROUP BY account_id
            )
            SELECT a.code, a.name, a.type, a.subtype,
                   (CASE WHEN a.type = 'income' THEN m.credit - m.debit ELSE m.debit - m.credit END)::numeric(20,2)::text AS amount
              FROM movement m
              JOIN accounts a ON a.id = m.account_id
             WHERE a.type IN ('income', 'expense') AND (m.debit <> 0 OR m.credit <> 0)
             ORDER BY a.code
            SQL, [$companyId, $to, $companyId, $from, $companyId, $from, $to, $companyId]);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->code] = $row;
        }

        return $keyed;
    }

    /** Lines a general ledger report shows at most; past this the report says so and asks for a shorter period. */
    public const GL_MAX_LINES = 5000;

    /**
     * The general ledger for a period: for each account (or the one asked for), the balance brought forward, every
     * posted line with its entry, memo, contact and a running balance, and the balance at the end. Optionally only the
     * lines of one location, class or project (the balances then count only those lines too). Amounts are base
     * currency, debit minus credit; accounts without a balance or a line are left out unless asked for by name.
     *
     * @param array{location_id?: string, class_id?: string, project_id?: string} $dimensions
     * @return array{accounts: list<array<string, mixed>>, truncated: bool}
     */
    public function generalLedger(string $companyId, string $base, string $from, string $to, ?string $accountId = null, array $dimensions = []): array
    {
        $scoped = function ($q) use ($companyId, $accountId, $dimensions) {
            $q->where('l.company_id', $companyId)->when($accountId, fn ($b, $id) => $b->where('l.account_id', $id));
            foreach ($dimensions as $column => $id) {
                $q->where("l.{$column}", $id);   // column names come from a fixed list (Dimension::COLUMNS), never the request
            }

            return $q;
        };
        $opening = $scoped(DB::table('journal_lines as l'))->where('l.entry_date', '<', $from)->groupBy('l.account_id')
            ->select('l.account_id')->selectRaw('sum(l.debit - l.credit)::numeric(20,2)::text AS balance')->pluck('balance', 'account_id');
        $lines = $scoped(DB::table('journal_lines as l'))
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('e.number')->orderBy('l.line_no')->limit(self::GL_MAX_LINES + 1)
            ->get(['l.account_id', 'l.entry_date as date', 'e.id as entry_id', 'e.number as entry', 'e.source_type', 'e.memo', 'l.description', 'c.name as contact', 'l.debit', 'l.credit']);
        $truncated = $lines->count() > self::GL_MAX_LINES;
        $lines = $lines->take(self::GL_MAX_LINES)->groupBy('account_id');

        $ids = collect([...$opening->keys(), ...$lines->keys(), ...($accountId ? [$accountId] : [])])->unique()->values();
        $accounts = DB::table('accounts')->where('company_id', $companyId)->whereIn('id', $ids->all())->orderBy('code')->get(['id', 'code', 'name', 'type']);

        return [
            'accounts' => $accounts->map(function ($a) use ($opening, $lines, $base) {
                $balance = Money::of((string) ($opening[$a->id] ?? '0'), $base);
                $start = $balance;
                $debits = Money::zero($base);
                $credits = Money::zero($base);
                $rows = collect($lines[$a->id] ?? [])->map(function ($l) use (&$balance, &$debits, &$credits, $base) {
                    $d = Money::of((string) $l->debit, $base);
                    $c = Money::of((string) $l->credit, $base);
                    $balance = $balance->plus($d)->minus($c);
                    $debits = $debits->plus($d);
                    $credits = $credits->plus($c);

                    return ['date' => $l->date, 'entry_id' => $l->entry_id, 'entry' => (int) $l->entry, 'source_type' => $l->source_type, 'memo' => $l->memo,
                        'description' => $l->description, 'contact' => $l->contact, 'debit' => $d->toDecimalString(), 'credit' => $c->toDecimalString(),
                        'balance' => $balance->toDecimalString()];
                })->values()->all();

                return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'opening' => $start->toDecimalString(),
                    'lines' => $rows, 'debits' => $debits->toDecimalString(), 'credits' => $credits->toDecimalString(), 'closing' => $balance->toDecimalString()];
            })->values()->all(),
            'truncated' => $truncated,
        ];
    }
}
