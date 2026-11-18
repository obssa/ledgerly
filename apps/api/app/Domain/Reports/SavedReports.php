<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Module 10, saved and scheduled reports (045): which reports can be saved, their options, what a rolling period
 * covers on a given day, and when a schedule sends next. The lists mirror the database's checks.
 */
final class SavedReports
{
    public const REPORTS = ['profit-and-loss', 'balance-sheet', 'trial-balance', 'general-ledger', 'cash-flow', 'changes-in-equity',
        'financial-health', 'profit-by-dimension', 'sales', 'purchases', 'ar-ageing', 'ap-ageing', 'inventory-valuation', 'stock-movement', 'stock-ageing', 'custom'];

    /** Reports of one date (the period's last day): the rest cover the period. */
    public const AT_A_DATE = ['balance-sheet', 'trial-balance', 'ar-ageing', 'ap-ageing', 'inventory-valuation', 'stock-ageing'];

    public const PERIODS = ['custom', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'year_to_date'];

    public const SCHEDULES = ['none', 'weekly', 'monthly'];

    /** The options each report takes, and the values allowed (a list, or 'uuid'). */
    public const OPTIONS = [
        'general-ledger' => ['account_id' => 'uuid'],
        'profit-by-dimension' => ['kind' => ['location', 'class', 'project']],
        'sales' => ['group' => ['contact', 'item', 'month', 'salesperson']],
        'purchases' => ['group' => ['contact', 'item', 'month']],
        'stock-movement' => ['warehouse_id' => 'uuid'],
        'stock-ageing' => ['warehouse_id' => 'uuid'],
        'custom' => ['rows' => CustomReport::ROWS, 'columns' => CustomReport::COLUMNS, 'measure' => CustomReport::MEASURES,
            'account_type' => CustomReport::ACCOUNT_TYPES, 'project_id' => 'uuid', 'location_id' => 'uuid', 'class_id' => 'uuid'],
    ];

    /**
     * The dates a saved report covers on a day: a custom range as saved; a rolling one from that day.
     *
     * @return array{from: string, to: string}
     */
    public function period(string $companyId, string $period, ?string $from, ?string $to, string $today): array
    {
        $day = new DateTimeImmutable($today);
        [$f, $t] = match ($period) {
            'custom' => [$from ?? $to, $to],
            'this_month' => [$day->modify('first day of this month')->format('Y-m-d'), $today],
            'last_month' => [$day->modify('first day of last month')->format('Y-m-d'), $day->modify('last day of last month')->format('Y-m-d')],
            'this_quarter' => [$this->quarterStart($day)->format('Y-m-d'), $today],
            'last_quarter' => [$this->quarterStart($day)->modify('-3 months')->format('Y-m-d'), $this->quarterStart($day)->modify('-1 day')->format('Y-m-d')],
            'year_to_date' => [(string) (DB::table('fiscal_years')->where('company_id', $companyId)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->value('starts_on')
                ?? $day->format('Y') . '-01-01'), $today],
            default => throw new \InvalidArgumentException('Unknown period'),
        };

        return ['from' => (string) $f, 'to' => (string) $t];
    }

    /** The next day a schedule sends after a day: the Monday after for weekly, the first of the next month for monthly. */
    public function nextSend(string $schedule, string $after): ?string
    {
        $day = new DateTimeImmutable($after);

        return match ($schedule) {
            'weekly' => $day->modify('next monday')->format('Y-m-d'),
            'monthly' => $day->modify('first day of next month')->format('Y-m-d'),
            default => null,
        };
    }

    /** A rolling period as words, for the list and the email. */
    public static function periodLabel(string $period): string
    {
        return match ($period) {
            'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter',
            'year_to_date' => 'Fiscal year to date', default => 'Custom dates',
        };
    }

    private function quarterStart(DateTimeImmutable $day): DateTimeImmutable
    {
        $month = intdiv((int) $day->format('n') - 1, 3) * 3 + 1;

        return new DateTimeImmutable(sprintf('%s-%02d-01', $day->format('Y'), $month));
    }
}
