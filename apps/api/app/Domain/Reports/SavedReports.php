<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Support\ReportPeriods;
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

    /** A custom range, or any report period the pages offer (ReportPeriods, schema 105); year_to_date is kept for older saves. */
    public const PERIODS = ['custom', 'year_to_date', ...ReportPeriods::PRESETS];

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
     * The dates a saved report covers on a day: a custom range as saved; a rolling one from that day, in the company's
     * calendar and fiscal year (ReportPeriods, the same rules as the report pages). An open end is null ("All dates",
     * "Since 30 days ago").
     *
     * @return array{from: ?string, to: ?string}
     */
    public function period(string $companyId, string $period, ?string $from, ?string $to, string $today): array
    {
        if ($period === 'custom') {
            return ['from' => $from ?? $to, 'to' => $to];
        }
        $company = DB::table('companies')->where('id', $companyId)->first(['calendar', 'fiscal_year_start_month']);
        $years = DB::table('fiscal_years')->where('company_id', $companyId)->orderBy('starts_on')->get(['starts_on', 'ends_on'])
            ->map(fn ($y) => ['starts_on' => (string) $y->starts_on, 'ends_on' => (string) $y->ends_on])->all();
        $periods = new ReportPeriods($today, (string) ($company->calendar ?? 'gregorian'), (int) ($company->fiscal_year_start_month ?? 1), $years);

        return $periods->resolve($period === 'year_to_date' ? 'this_fiscal_year_to_date' : $period);
    }

    /**
     * The date a report "as of" shows for a period: its last day. A rolling period never looks past today (a balance
     * sheet for "This month" is today's, not the month end's yet to come); a custom date is shown as chosen.
     *
     * @param  array{from: ?string, to: ?string}  $dates
     */
    public static function asOf(string $period, array $dates, string $today): string
    {
        if ($dates['to'] === null) {
            return $today;
        }

        return $period !== 'custom' && $dates['to'] > $today ? $today : $dates['to'];
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
            'custom' => 'Custom dates',
            'year_to_date' => 'Fiscal year to date',
            default => ReportPeriods::LABELS[$period] ?? 'Custom dates',
        };
    }
}
