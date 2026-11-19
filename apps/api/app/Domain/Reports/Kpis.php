<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 10: the key figures on the home page, read from the books as they stand (base currency):
 *   - cash: what the bank, cash and wallet accounts hold today, and a month ago;
 *   - receivable: what customers owe (the receivables account), and how much of it is overdue on posted invoices;
 *   - payable: what is owed to suppliers (the payables account), and what is due within the next seven days;
 *   - this month's sales and net profit to date, against the whole of last month (accrual basis).
 * Every query is the company's own under row-level security; nothing is stored.
 */
final class Kpis
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** @return array<string, mixed> */
    public function figures(string $companyId, string $currency, string $today): array
    {
        $at = new DateTimeImmutable($today);
        $monthAgo = $at->modify('-1 month')->format('Y-m-d');
        $balance = function (string $date, ?array $subtypes, ?string $systemKey, bool $creditPositive = false) use ($companyId): string {
            $q = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.company_id', $companyId)->where('l.entry_date', '<=', $date)
                ->when($subtypes !== null, fn ($b) => $b->whereIn('a.subtype', $subtypes))
                ->when($systemKey !== null, fn ($b) => $b->where('a.system_key', $systemKey));

            return (string) $q->selectRaw($creditPositive ? 'coalesce(sum(l.credit - l.debit), 0)::numeric(20,2)::text AS b' : 'coalesce(sum(l.debit - l.credit), 0)::numeric(20,2)::text AS b')->value('b');
        };
        $open = fn (string $type) => DB::table('documents')->where(['company_id' => $companyId, 'type' => $type])->whereIn('status', ['posted', 'part_paid'])
            ->where('amount_due', '>', 0);

        $overdue = $open('invoice')->where('due_date', '<', $today)
            ->selectRaw('count(*) AS n, coalesce(sum(round(amount_due * fx_rate, 2)), 0)::numeric(20,2)::text AS amount')->first();
        $dueSoon = $open('bill')->where('due_date', '<=', $at->modify('+7 days')->format('Y-m-d'))
            ->selectRaw('count(*) AS n, coalesce(sum(round(amount_due * fx_rate, 2)), 0)::numeric(20,2)::text AS amount')->first();

        $monthStart = $at->modify('first day of this month')->format('Y-m-d');
        $lastStart = $at->modify('first day of last month')->format('Y-m-d');
        $lastEnd = $at->modify('last day of last month')->format('Y-m-d');
        $pl = $this->reports->profitAndLoss($companyId, $currency, $monthStart, $today, $lastStart, $lastEnd);

        $cash = $balance($today, ['bank', 'cash', 'mobile_money'], null);
        $cashBefore = $balance($monthAgo, ['bank', 'cash', 'mobile_money'], null);

        return [
            'as_of' => $today, 'currency' => $currency,
            'cash' => ['now' => $cash, 'month_ago' => $cashBefore, 'change' => bcsub($cash, $cashBefore, 2)],
            'receivable' => ['balance' => $balance($today, null, 'accounts_receivable'), 'overdue' => (string) $overdue->amount, 'overdue_count' => (int) $overdue->n],
            'payable' => ['balance' => $balance($today, null, 'accounts_payable', true), 'due_this_week' => (string) $dueSoon->amount, 'due_this_week_count' => (int) $dueSoon->n],
            'month' => [
                'from' => $monthStart, 'to' => $today, 'last_from' => $lastStart, 'last_to' => $lastEnd,
                'sales' => $pl['totals']['income']['current'], 'sales_last' => $pl['totals']['income']['previous'],
                'net_profit' => $pl['totals']['net_profit']['current'], 'net_profit_last' => $pl['totals']['net_profit']['previous'],
            ],
        ];
    }
}
