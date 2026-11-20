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
    private function movements(string $companyId, ?string $from, string $to, bool $withoutRevaluations = false): array
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)->where('l.entry_date', '<=', $to)
            ->when($from, fn ($q, $v) => $q->where('l.entry_date', '>=', $v))
            ->where('e.source_type', '<>', 'closing')
            ->when($withoutRevaluations, fn ($q) => $q->where('e.source_type', '<>', 'asset_revaluation'))   // 092: no cash in them
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
        // 092: a revaluation moves no cash: its entries are left out, and what it put through profit or loss is added
        // back as not cash, so the profit shown is still the period's.
        $rows = $this->movements($companyId, $from, $to, true);
        $revaluedProfit = BigDecimal::of((string) DB::table('asset_revaluations')->where('company_id', $companyId)
            ->whereBetween('revalued_on', [$from, $to])->sum('to_profit'));
        $profit = $revaluedProfit;
        $cashChange = BigDecimal::zero();
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        if (! $revaluedProfit->isZero()) {
            $sections['operating'][] = ['code' => null, 'name' => 'Revaluation of fixed assets (not cash)', 'amount' => (string) $revaluedProfit->negated()->toScale(2)];
        }
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
     * Module 10: the notes to the financial statements for a period, from the books as they stand: the accounting
     * policies the company uses, and the notes behind the balance sheet figures (property, plant and equipment with its
     * depreciation, receivables, inventory, payables and accruals, taxes, loans). Each note is opening, movement and
     * closing (or the closing balance), so it agrees with the statements of the same period.
     *
     * @return array<string, mixed>
     */
    public function notes(string $companyId, string $from, string $to): array
    {
        $before = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $opening = collect($this->movements($companyId, null, $before))->keyBy('id');
        $closing = collect($this->movements($companyId, null, $to))->keyBy('id');
        $period = collect($this->movements($companyId, $from, $to))->keyBy('id');
        $sum = fn ($set, callable $pick) => $set->filter($pick)->reduce(fn (BigDecimal $t, $a) => $t->plus($a->amount), BigDecimal::zero());
        $str = fn (BigDecimal $v) => (string) $v->toScale(2);
        $isPpe = fn ($a) => $a->type === 'asset' && $a->subtype === 'fixed_asset';
        $isDep = fn ($a) => self::isDepreciation($a);
        // Additions and disposals at cost: the debits and credits to the cost accounts in the period.
        $notRevaluation = fn ($q) => $q->whereNotExists(fn ($s) => $s->from('journal_entries as e')->whereColumn('e.company_id', 'l.company_id')
            ->whereColumn('e.id', 'l.journal_entry_id')->where('e.source_type', 'asset_revaluation'));
        $ppeMoves = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where($notRevaluation)
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])->where('a.type', 'asset')->where('a.subtype', 'fixed_asset')
            ->selectRaw('coalesce(sum(l.debit), 0)::numeric(20,2)::text AS additions, coalesce(sum(l.credit), 0)::numeric(20,2)::text AS disposals')->first();
        $depMoves = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where($notRevaluation)
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])->where('a.type', 'asset')->where('a.subtype', 'contra_asset')
            ->where(fn ($q) => $q->whereNull('a.system_key')->orWhere('a.system_key', '<>', 'doubtful_debts_allowance'))
            ->selectRaw('coalesce(sum(l.credit), 0)::numeric(20,2)::text AS charged, coalesce(sum(l.debit), 0)::numeric(20,2)::text AS released')->first();
        $revaluedIn = DB::table('asset_revaluations')->where('company_id', $companyId)->whereBetween('revalued_on', [$from, $to])->get(['to_surplus', 'to_profit']);
        $up = $revaluedIn->reduce(fn (BigDecimal $t, $r) => ($c = BigDecimal::of((string) $r->to_surplus)->plus((string) $r->to_profit))->isPositive() ? $t->plus($c) : $t, BigDecimal::zero());
        $down = $revaluedIn->reduce(fn (BigDecimal $t, $r) => ($c = BigDecimal::of((string) $r->to_surplus)->plus((string) $r->to_profit))->isNegative() ? $t->minus($c) : $t, BigDecimal::zero());
        $costOpen = $sum($opening, $isPpe);
        $costClose = $sum($closing, $isPpe);
        $depOpen = $sum($opening, $isDep)->negated();
        $depClose = $sum($closing, $isDep)->negated();
        $balance = fn (callable $pick, bool $credit = false) => $credit ? $sum($closing, $pick)->negated() : $sum($closing, $pick);
        $lines = fn (callable $pick, bool $credit = false) => $closing->filter($pick)->filter(fn ($a) => ! BigDecimal::of($a->amount)->isZero())
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'amount' => $str($credit ? BigDecimal::of($a->amount)->negated() : BigDecimal::of($a->amount))])->values()->all();
        $company = DB::table('companies')->where('id', $companyId)->first(['legal_name', 'base_currency', 'framework', 'fiscal_year_start_month', 'accounting_basis']);
        $fifo = DB::table('items')->where(['company_id' => $companyId, 'type' => 'inventory', 'costing' => 'fifo', 'is_active' => true])->count();
        $methods = DB::table('fixed_assets')->where('company_id', $companyId)->distinct()->pluck('method')->filter()->values()->all();

        return [
            'from' => $from, 'to' => $to, 'company' => $company->legal_name, 'currency' => $company->base_currency,
            'policies' => [
                'framework' => 'IFRS for SMEs', 'basis' => $company->accounting_basis ?? 'accrual', 'fiscal_year_start_month' => (int) $company->fiscal_year_start_month,
                'inventory' => $fifo > 0 ? "Weighted average cost; {$fifo} items first in, first out" : 'Weighted average cost',
                'depreciation_methods' => $methods,
                'revaluation' => DB::table('asset_revaluations')->where('company_id', $companyId)->where('revalued_on', '<=', $to)->exists(),   // 092
            ],
            'ppe' => [
                'cost' => ['opening' => $str($costOpen), 'additions' => (string) $ppeMoves->additions, 'revaluations' => $str($up), 'disposals' => (string) $ppeMoves->disposals, 'closing' => $str($costClose)],
                'depreciation' => ['opening' => $str($depOpen), 'charged' => (string) $depMoves->charged, 'revaluations' => $str($down), 'released' => (string) $depMoves->released, 'closing' => $str($depClose)],
                'carrying_opening' => $str($costOpen->minus($depOpen)), 'carrying_closing' => $str($costClose->minus($depClose)),
            ],
            'receivables' => ['lines' => $lines(fn ($a) => in_array($a->system_key, ['accounts_receivable', 'doubtful_debts_allowance', 'withholding_receivable'], true) || $a->subtype === 'receivable'),
                'total' => $str($balance(fn ($a) => in_array($a->system_key, ['accounts_receivable', 'doubtful_debts_allowance', 'withholding_receivable'], true) || $a->subtype === 'receivable'))],
            'inventory' => ['lines' => $lines(fn ($a) => $a->type === 'asset' && $a->subtype === 'inventory'), 'total' => $str($balance(fn ($a) => $a->type === 'asset' && $a->subtype === 'inventory'))],
            'payables' => ['lines' => $lines(fn ($a) => $a->type === 'liability' && in_array($a->subtype, ['payable', 'accrual', 'payroll', 'deposit'], true), true),
                'total' => $str($balance(fn ($a) => $a->type === 'liability' && in_array($a->subtype, ['payable', 'accrual', 'payroll', 'deposit'], true), true))],
            'taxes' => ['lines' => $lines(fn ($a) => $a->type === 'liability' && $a->subtype === 'tax_payable', true),
                'total' => $str($balance(fn ($a) => $a->type === 'liability' && $a->subtype === 'tax_payable', true))],
            'loans' => ['lines' => $lines(fn ($a) => $a->type === 'liability' && $a->subtype === 'loan', true),
                'total' => $str($balance(fn ($a) => $a->type === 'liability' && $a->subtype === 'loan', true)),
                'movement' => $str($sum($period, fn ($a) => $a->type === 'liability' && $a->subtype === 'loan')->negated())],
        ];
    }

    /** Where cash paid or received is shown on the direct-method statement, by the account on the other side of it. */
    public const DIRECT_LINES = [
        'customers' => 'Received from customers', 'suppliers' => 'Paid to suppliers and for expenses', 'employees' => 'Paid to employees',
        'taxes' => 'Taxes paid and received', 'other' => 'Other operating cash', 'investing' => 'Investing', 'financing' => 'Financing',
    ];

    /**
     * The cash flow statement for a period, direct method: the cash that moved through the bank, cash and wallet accounts,
     * shown by what it was for: each entry that touched a cash account is classified by its other lines (credit less
     * debit of each is the cash it brought in), so the lines add up exactly to the change in cash; transfers between the
     * company's own cash accounts cancel out.
     *
     * @return array<string, mixed>
     */
    public function directCashFlow(string $companyId, string $from, string $to): array
    {
        $cash = DB::table('accounts')->where(['company_id' => $companyId, 'type' => 'asset'])->whereIn('subtype', self::CASH)->pluck('id')->all();
        $touched = DB::table('journal_lines')->where('company_id', $companyId)->whereBetween('entry_date', [$from, $to])->whereIn('account_id', $cash)
            ->select('journal_entry_id');
        $rows = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $companyId)->whereBetween('l.entry_date', [$from, $to])->whereNotIn('l.account_id', $cash)
            ->whereIn('l.journal_entry_id', $touched)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.subtype', 'a.system_key')->orderBy('a.code')
            ->select('a.code', 'a.name', 'a.type', 'a.subtype', 'a.system_key')
            ->selectRaw('sum(l.credit - l.debit)::numeric(20,2)::text AS amount')->get();
        $lines = array_fill_keys(array_keys(self::DIRECT_LINES), []);
        foreach ($rows as $a) {
            if (BigDecimal::of($a->amount)->isZero()) {
                continue;
            }
            $lines[self::directLine($a)][] = ['code' => $a->code, 'name' => $a->name, 'amount' => $a->amount];
        }
        $sum = fn (array $ls) => array_reduce($ls, fn (BigDecimal $t, array $l) => $t->plus($l['amount']), BigDecimal::zero());
        $operating = $sum([...$lines['customers'], ...$lines['suppliers'], ...$lines['employees'], ...$lines['taxes'], ...$lines['other']]);
        $investing = $sum($lines['investing']);
        $financing = $sum($lines['financing']);
        $cashChange = BigDecimal::of((string) (DB::table('journal_lines')->where('company_id', $companyId)->whereBetween('entry_date', [$from, $to])
            ->whereIn('account_id', $cash)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS v')->value('v') ?? '0'));
        $opening = BigDecimal::of((string) (DB::table('journal_lines')->where('company_id', $companyId)->where('entry_date', '<', $from)
            ->whereIn('account_id', $cash)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS v')->value('v') ?? '0'));
        $net = $operating->plus($investing)->plus($financing);

        return [
            'from' => $from, 'to' => $to, 'method' => 'direct',
            'lines' => array_map(fn (string $key) => ['key' => $key, 'label' => self::DIRECT_LINES[$key], 'accounts' => $lines[$key],
                'amount' => (string) $sum($lines[$key])->toScale(2)], array_keys(self::DIRECT_LINES)),
            'totals' => ['operating' => (string) $operating->toScale(2), 'investing' => (string) $investing->toScale(2), 'financing' => (string) $financing->toScale(2)],
            'net_change' => (string) $net->toScale(2),
            'opening_cash' => (string) $opening->toScale(2), 'closing_cash' => (string) $opening->plus($cashChange)->toScale(2),
            'cash_change' => (string) $cashChange->toScale(2),
            'balanced' => $net->isEqualTo($cashChange),
        ];
    }

    private static function directLine(object $a): string
    {
        return match (true) {
            in_array($a->system_key, ['accounts_receivable', 'customer_deposits', 'doubtful_debts_allowance'], true) || $a->type === 'income' && $a->subtype !== 'other_income' && $a->system_key !== 'asset_disposal' => 'customers',
            in_array($a->system_key, ['salaries_payable', 'salaries_expense', 'employer_contributions', 'paye_payable'], true) => 'employees',
            in_array($a->system_key, ['vat_payable', 'vat_receivable', 'withholding_payable', 'withholding_receivable', 'income_tax_payable', 'income_tax_expense'], true) => 'taxes',
            ($a->type === 'asset' && $a->subtype === 'fixed_asset') || $a->system_key === 'asset_disposal' => 'investing',
            ($a->type === 'liability' && $a->subtype === 'loan') || ($a->type === 'equity' && $a->subtype !== 'retained_earnings') => 'financing',
            in_array($a->system_key, ['accounts_payable', 'grni', 'inventory', 'cogs'], true) || $a->type === 'expense' || ($a->type === 'asset' && in_array($a->subtype, ['inventory', 'prepayment'], true)) => 'suppliers',
            default => 'other',
        };
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
