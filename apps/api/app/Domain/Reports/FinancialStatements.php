<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Module 10: the statements built from account movements (closing entries and their reversals left out, so a year's
 * close moves nothing here): the cash flow statement (indirect method), the statement of changes in equity, and the
 * financial health (liquidity) pack. Every figure is a sum of ledger lines; accounts are placed by their kind:
 *
 *   cash         cash, bank and mobile-money accounts (undeposited funds is cash not yet banked)
 *   investing    property, plant and equipment at cost (fixed asset accounts)
 *   financing    loans, and equity other than retained earnings
 *   operating    everything else on the balance sheet (working capital), and accumulated depreciation as the
 *                non-cash charge added back
 */
final class FinancialStatements
{
    private const CASH = ['cash', 'bank', 'mobile_money'];

    /**
     * Each account's movement for a period (debit-positive), or its balance at a date when $from is null.
     *
     * @return list<object{id: string, code: string, name: string, type: string, subtype: ?string, system_key: ?string, amount: string}>
     */
    private function movements(string $companyId, ?string $from, string $to): array
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)->where('l.entry_date', '<=', $to)
            ->when($from, fn ($q, $v) => $q->where('l.entry_date', '>=', $v))
            ->where('e.source_type', '<>', 'closing')
            ->whereNotExists(fn ($q) => $q->from('journal_entries as c')->whereColumn('c.company_id', 'e.company_id')->whereColumn('c.id', 'e.source_id')
                ->where('e.source_type', 'reversal')->where('c.source_type', 'closing'))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.subtype', 'a.system_key')->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.type', 'a.subtype', 'a.system_key')
            ->selectRaw('sum(l.debit - l.credit)::numeric(20,2)::text AS amount')
            ->get()->all();
    }

    private static function isCash(object $a): bool
    {
        return $a->type === 'asset' && in_array($a->subtype, self::CASH, true);
    }

    private static function isDepreciation(object $a): bool
    {
        return $a->type === 'asset' && $a->subtype === 'contra_asset' && $a->system_key !== 'doubtful_debts_allowance';
    }

    /**
     * The cash flow statement for a period, indirect method: net profit, the non-cash charge and the changes in working
     * capital (operating), then investing and financing; their total is the change in cash, checked against the cash
     * accounts.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(string $companyId, string $from, string $to): array
    {
        $rows = $this->movements($companyId, $from, $to);
        $profit = BigDecimal::zero();
        $cashChange = BigDecimal::zero();
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($rows as $a) {
            $amount = BigDecimal::of($a->amount);
            if (in_array($a->type, ['income', 'expense'], true)) {
                $profit = $profit->minus($amount);   // income is a credit
                continue;
            }
            if (self::isCash($a)) {
                $cashChange = $cashChange->plus($amount);
                continue;
            }
            // A rise in a non-cash asset uses cash; a rise in a liability or equity brings it in.
            $effect = $amount->negated();
            if ($effect->isZero()) {
                continue;
            }
            $section = match (true) {
                $a->type === 'asset' && $a->subtype === 'fixed_asset' => 'investing',
                ($a->type === 'liability' && $a->subtype === 'loan') || ($a->type === 'equity' && $a->subtype !== 'retained_earnings') => 'financing',
                default => 'operating',
            };
            $label = self::isDepreciation($a) ? "Depreciation charged ({$a->name})" : $a->name;
            $sections[$section][] = ['code' => $a->code, 'name' => $label, 'amount' => (string) $effect->toScale(2)];
        }
        $totals = [];
        foreach ($sections as $key => $lines) {
            $totals[$key] = array_reduce($lines, fn (BigDecimal $t, array $l) => $t->plus($l['amount']), $key === 'operating' ? $profit : BigDecimal::zero());
        }
        $net = $totals['operating']->plus($totals['investing'])->plus($totals['financing']);
        $opening = array_reduce($this->movements($companyId, null, (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d')),
            fn (BigDecimal $t, object $a) => self::isCash($a) ? $t->plus($a->amount) : $t, BigDecimal::zero());

        return [
            'from' => $from, 'to' => $to, 'net_profit' => (string) $profit->toScale(2),
            'sections' => $sections,
            'totals' => array_map(fn (BigDecimal $t) => (string) $t->toScale(2), $totals),
            'net_change' => (string) $net->toScale(2),
            'opening_cash' => (string) $opening->toScale(2), 'closing_cash' => (string) $opening->plus($cashChange)->toScale(2),
            'cash_change' => (string) $cashChange->toScale(2),
            'balanced' => $net->isEqualTo($cashChange),
        ];
    }

    /**
     * The statement of changes in equity for a period: each equity account's opening balance, what moved in it, and its
     * closing balance (credit-positive). Retained earnings are shown with the profit, closed or not: closing entries are
     * left out of every movement, so the line is the company's profit kept, whichever years are closed.
     *
     * @return array<string, mixed>
     */
    public function changesInEquity(string $companyId, string $from, string $to): array
    {
        $before = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $opening = collect($this->movements($companyId, null, $before))->keyBy('id');
        $moved = collect($this->movements($companyId, $from, $to))->keyBy('id');
        $credit = fn ($a) => $a === null ? BigDecimal::zero() : BigDecimal::of($a->amount)->negated();
        $kept = ['opening' => BigDecimal::zero(), 'movement' => BigDecimal::zero()];
        $profit = BigDecimal::zero();
        $lines = [];
        foreach ($opening->union($moved)->sortBy('code') as $id => $a) {
            $o = $credit($opening[$id] ?? null);
            $m = $credit($moved[$id] ?? null);
            if (in_array($a->type, ['income', 'expense'], true) || $a->subtype === 'retained_earnings') {
                $kept['opening'] = $kept['opening']->plus($o);
                $kept['movement'] = $kept['movement']->plus($m);
                if ($a->type !== 'equity') {
                    $profit = $profit->plus($m);
                }
                continue;
            }
            if ($a->type !== 'equity' || ($o->isZero() && $m->isZero())) {
                continue;
            }
            $lines[] = ['code' => $a->code, 'name' => $a->name, 'opening' => (string) $o->toScale(2), 'movement' => (string) $m->toScale(2), 'closing' => (string) $o->plus($m)->toScale(2)];
        }
        $lines[] = ['code' => null, 'name' => 'Retained earnings and profit', 'opening' => (string) $kept['opening']->toScale(2),
            'movement' => (string) $kept['movement']->toScale(2), 'closing' => (string) $kept['opening']->plus($kept['movement'])->toScale(2)];
        $total = fn (string $k) => (string) array_reduce($lines, fn (BigDecimal $t, array $l) => $t->plus($l[$k]), BigDecimal::zero())->toScale(2);

        return ['from' => $from, 'to' => $to, 'lines' => $lines, 'net_profit' => (string) $profit->toScale(2),
            'opening' => $total('opening'), 'movement' => $total('movement'), 'closing' => $total('closing')];
    }

    /**
     * The financial health (liquidity) pack at the end of a period: current and quick ratios, cash runway, DSO, DPO,
     * and gross and net margin for the period. A ratio with nothing to divide by is null, never infinite.
     *
     * @return array<string, mixed>
     */
    public function health(string $companyId, string $from, string $to): array
    {
        $balances = $this->movements($companyId, null, $to);
        $sum = fn (callable $pick, bool $creditPositive = false) => array_reduce($balances, fn (BigDecimal $t, object $a) => $pick($a)
            ? $t->plus($creditPositive ? BigDecimal::of($a->amount)->negated() : $a->amount) : $t, BigDecimal::zero());
        $currentAssets = $sum(fn ($a) => $a->type === 'asset' && $a->subtype !== 'fixed_asset' && ! self::isDepreciation($a));
        $inventory = $sum(fn ($a) => $a->type === 'asset' && $a->subtype === 'inventory');
        $cash = $sum(fn ($a) => self::isCash($a));
        $receivables = $sum(fn ($a) => $a->system_key === 'accounts_receivable');
        $currentLiabilities = $sum(fn ($a) => $a->type === 'liability' && $a->subtype !== 'loan', true);
        $payables = $sum(fn ($a) => $a->system_key === 'accounts_payable', true);

        $period = $this->movements($companyId, $from, $to);
        $income = array_reduce($period, fn (BigDecimal $t, object $a) => $a->type === 'income' ? $t->minus($a->amount) : $t, BigDecimal::zero());
        $cogs = array_reduce($period, fn (BigDecimal $t, object $a) => $a->type === 'expense' && $a->subtype === 'cogs' ? $t->plus($a->amount) : $t, BigDecimal::zero());
        $expenses = array_reduce($period, fn (BigDecimal $t, object $a) => $a->type === 'expense' ? $t->plus($a->amount) : $t, BigDecimal::zero());
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $docs = fn (array $types) => BigDecimal::of((string) (DB::table('documents')->where('company_id', $companyId)->whereIn('type', $types)
            ->whereIn('status', ['posted', 'part_paid', 'paid'])->whereBetween('issue_date', [$from, $to])
            ->selectRaw('coalesce(sum(round(total * fx_rate, 2)), 0)::numeric(20,2)::text AS v')->value('v') ?? '0'));
        $creditSales = $docs(['invoice'])->minus($docs(['credit_note']));
        $purchases = $docs(['bill'])->minus($docs(['vendor_credit']));
        // Cash runway: cash today over the average monthly net outflow of the last three months (none when cash grew).
        $threeMonths = (new \DateTimeImmutable($to))->modify('-3 months')->modify('+1 day')->format('Y-m-d');
        $cashMove = array_reduce($this->movements($companyId, $threeMonths, $to), fn (BigDecimal $t, object $a) => self::isCash($a) ? $t->plus($a->amount) : $t, BigDecimal::zero());
        $burn = $cashMove->isNegative() ? $cashMove->negated()->dividedBy(3, 2, \App\Domain\Ledger\Rounding::halfUp()) : null;

        $ratio = fn (BigDecimal $n, BigDecimal $d, int $scale = 2) => $d->isZero() ? null : (string) $n->dividedBy($d, $scale, \App\Domain\Ledger\Rounding::halfUp());
        $gross = $income->minus($cogs);

        return [
            'from' => $from, 'to' => $to, 'days' => $days,
            'figures' => ['current_assets' => (string) $currentAssets->toScale(2), 'current_liabilities' => (string) $currentLiabilities->toScale(2),
                'inventory' => (string) $inventory->toScale(2), 'cash' => (string) $cash->toScale(2), 'receivables' => (string) $receivables->toScale(2),
                'payables' => (string) $payables->toScale(2), 'revenue' => (string) $income->toScale(2), 'gross_profit' => (string) $gross->toScale(2),
                'net_profit' => (string) $income->minus($expenses)->toScale(2), 'credit_sales' => (string) $creditSales->toScale(2),
                'purchases' => (string) $purchases->toScale(2), 'monthly_cash_outflow' => $burn === null ? null : (string) $burn],
            'ratios' => [
                'current_ratio' => $ratio($currentAssets, $currentLiabilities),
                'quick_ratio' => $ratio($currentAssets->minus($inventory), $currentLiabilities),
                'cash_runway_months' => $burn === null || $burn->isZero() ? null : $ratio($cash, $burn, 1),
                'dso_days' => $ratio($receivables->multipliedBy($days), $creditSales, 0),
                'dpo_days' => $ratio($payables->multipliedBy($days), $purchases, 0),
                'gross_margin' => $ratio($gross->multipliedBy(100), $income, 1),
                'net_margin' => $ratio($income->minus($expenses)->multipliedBy(100), $income, 1),
            ],
        ];
    }
}
