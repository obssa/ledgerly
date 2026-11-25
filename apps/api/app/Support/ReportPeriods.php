<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Report periods as QuickBooks offers them (This month to date, Last fiscal year, Since 30 days ago …), worked out in the
 * company's own calendar: the same rules as the web app's apps/web/src/lib/periods.ts, so a saved or scheduled report
 * covers on the server what its page shows. An Ethiopian-calendar company's months are Ethiopian months (Pagume with
 * Nehase, as FiscalCalendar does), its year starts on Meskerem 1, its quarters are three of its months; weeks run Monday
 * to Sunday. Dates are Gregorian "Y-m-d", as stored; null is an open end.
 */
final class ReportPeriods
{
    public const PRESETS = ['all', 'today', 'yesterday', 'this_week', 'this_week_to_date', 'last_week', 'last_week_to_date', 'next_week', 'next_4_weeks',
        'this_month', 'this_month_to_date', 'last_month', 'last_month_to_date', 'next_month',
        'this_quarter', 'this_quarter_to_date', 'last_quarter', 'last_quarter_to_date', 'next_quarter',
        'this_year', 'this_year_to_date', 'this_year_to_last_month', 'last_year', 'last_year_to_date', 'next_year',
        'this_fiscal_year', 'this_fiscal_year_to_date', 'this_fiscal_year_to_last_month', 'last_fiscal_year', 'last_fiscal_year_to_date',
        'this_fiscal_quarter', 'this_fiscal_quarter_to_date', 'last_fiscal_quarter',
        'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days', 'last_12_months',
        'since_30_days_ago', 'since_60_days_ago', 'since_90_days_ago', 'since_365_days_ago'];

    public const LABELS = ['all' => 'All dates', 'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_week_to_date' => 'This week to date',
        'last_week' => 'Last week', 'last_week_to_date' => 'Last week to date', 'next_week' => 'Next week', 'next_4_weeks' => 'Next 4 weeks',
        'this_month' => 'This month', 'this_month_to_date' => 'This month to date', 'last_month' => 'Last month', 'last_month_to_date' => 'Last month to date', 'next_month' => 'Next month',
        'this_quarter' => 'This quarter', 'this_quarter_to_date' => 'This quarter to date', 'last_quarter' => 'Last quarter', 'last_quarter_to_date' => 'Last quarter to date', 'next_quarter' => 'Next quarter',
        'this_year' => 'This year', 'this_year_to_date' => 'This year to date', 'this_year_to_last_month' => 'This year to last month', 'last_year' => 'Last year',
        'last_year_to_date' => 'Last year to date', 'next_year' => 'Next year',
        'this_fiscal_year' => 'This fiscal year', 'this_fiscal_year_to_date' => 'This fiscal year to date', 'this_fiscal_year_to_last_month' => 'This fiscal year to last month',
        'last_fiscal_year' => 'Last fiscal year', 'last_fiscal_year_to_date' => 'Last fiscal year to date',
        'this_fiscal_quarter' => 'This fiscal quarter', 'this_fiscal_quarter_to_date' => 'This fiscal quarter to date', 'last_fiscal_quarter' => 'Last fiscal quarter',
        'last_7_days' => 'Last 7 days', 'last_30_days' => 'Last 30 days', 'last_90_days' => 'Last 90 days', 'last_365_days' => 'Last 365 days', 'last_12_months' => 'Last 12 months',
        'since_30_days_ago' => 'Since 30 days ago', 'since_60_days_ago' => 'Since 60 days ago', 'since_90_days_ago' => 'Since 90 days ago', 'since_365_days_ago' => 'Since 365 days ago'];

    /**
     * @param  list<array{starts_on: string, ends_on: string}>  $fiscalYears  the company's fiscal years as set up (they win)
     */
    public function __construct(
        private readonly string $today,
        private readonly string $calendar,          // 'gregorian' | 'ethiopian'
        private readonly int $fiscalStartMonth,     // 1-12, in the company's calendar
        private readonly array $fiscalYears = [],
    ) {
    }

    /** @return array{from: ?string, to: ?string} */
    public function resolve(string $preset): array
    {
        $t = $this->today;
        $month = $this->monthOf($t);
        $quarter = [$month[0], intdiv($month[1] - 1, 3) * 3 + 1];
        $year = [$month[0], 1];
        $week = $this->addDays($t, -((int) (new DateTimeImmutable($t))->format('N') - 1));
        $range = fn (?string $from, ?string $to) => ['from' => $from, 'to' => $to];
        $toLastMonth = function (string $from) use ($month): ?array {
            $to = $this->monthEnd($this->shift($month, -1));

            return $to < $from ? null : ['from' => $from, 'to' => $to];
        };

        return match ($preset) {
            'all' => $range(null, null),
            'today' => $range($t, $t),
            'yesterday' => $range($this->addDays($t, -1), $this->addDays($t, -1)),
            'this_week' => $range($week, $this->addDays($week, 6)),
            'this_week_to_date' => $range($week, $t),
            'last_week' => $range($this->addDays($week, -7), $this->addDays($week, -1)),
            'last_week_to_date' => $range($this->addDays($week, -7), $this->addDays($t, -7)),
            'next_week' => $range($this->addDays($week, 7), $this->addDays($week, 13)),
            'next_4_weeks' => $range($t, $this->addDays($t, 27)),
            'this_month' => $this->span($month, 1),
            'this_month_to_date' => $range($this->monthStart($month), $t),
            'last_month' => $this->span($this->shift($month, -1), 1),
            'last_month_to_date' => $range($this->monthStart($this->shift($month, -1)), $this->shiftDay($t, -1)),
            'next_month' => $this->span($this->shift($month, 1), 1),
            'this_quarter' => $this->span($quarter, 3),
            'this_quarter_to_date' => $range($this->monthStart($quarter), $t),
            'last_quarter' => $this->span($this->shift($quarter, -3), 3),
            'last_quarter_to_date' => $range($this->monthStart($this->shift($quarter, -3)), $this->shiftDay($t, -3)),
            'next_quarter' => $this->span($this->shift($quarter, 3), 3),
            'this_year' => $this->span($year, 12),
            'this_year_to_date' => $range($this->monthStart($year), $t),
            'this_year_to_last_month' => $toLastMonth($this->monthStart($year)) ?? $this->span($this->shift($year, -12), 12),
            'last_year' => $this->span($this->shift($year, -12), 12),
            'last_year_to_date' => $range($this->monthStart($this->shift($year, -12)), $this->shiftDay($t, -12)),
            'next_year' => $this->span($this->shift($year, 12), 12),
            'this_fiscal_year' => $this->fiscalYear(0),
            'this_fiscal_year_to_date' => $range($this->fiscalYear(0)['from'], $t),
            'this_fiscal_year_to_last_month' => $toLastMonth((string) $this->fiscalYear(0)['from']) ?? $this->fiscalYear(-1),
            'last_fiscal_year' => $this->fiscalYear(-1),
            'last_fiscal_year_to_date' => $range($this->fiscalYear(-1)['from'], $this->shiftDay($t, -12)),
            'this_fiscal_quarter' => $this->fiscalQuarter(0),
            'this_fiscal_quarter_to_date' => $range($this->fiscalQuarter(0)['from'], $t),
            'last_fiscal_quarter' => $this->fiscalQuarter(-1),
            'last_7_days' => $range($this->addDays($t, -6), $t),
            'last_30_days' => $range($this->addDays($t, -29), $t),
            'last_90_days' => $range($this->addDays($t, -89), $t),
            'last_365_days' => $range($this->addDays($t, -364), $t),
            'last_12_months' => $this->span($this->shift($month, -12), 12),
            'since_30_days_ago' => $range($this->addDays($t, -30), null),
            'since_60_days_ago' => $range($this->addDays($t, -60), null),
            'since_90_days_ago' => $range($this->addDays($t, -90), null),
            'since_365_days_ago' => $range($this->addDays($t, -365), null),
            default => throw new InvalidArgumentException("Unknown report period {$preset}"),
        };
    }

    /** @return array{from: string, to: string} */
    private function fiscalYear(int $offset): array
    {
        $set = $this->fiscalYears;
        usort($set, fn ($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        foreach ($set as $i => $f) {
            if ($f['starts_on'] <= $this->today && $this->today <= $f['ends_on'] && isset($set[$i + $offset])) {
                return ['from' => $set[$i + $offset]['starts_on'], 'to' => $set[$i + $offset]['ends_on']];
            }
        }
        [$y, $m] = $this->monthOf($this->today);
        $first = [$m >= $this->fiscalStartMonth ? $y : $y - 1, $this->fiscalStartMonth];

        return $this->span($this->shift($first, 12 * $offset), 12);
    }

    /** @return array{from: string, to: string} */
    private function fiscalQuarter(int $offset): array
    {
        $first = $this->monthOf((string) $this->fiscalYear(0)['from']);
        $now = $this->monthOf($this->today);
        $since = ($now[0] * 12 + $now[1]) - ($first[0] * 12 + $first[1]);

        return $this->span($this->shift($first, (intdiv($since, 3) + $offset) * 3), 3);
    }

    /** @return array{0: int, 1: int} year and month (1-12) in the company's calendar; Pagume counts as Nehase */
    private function monthOf(string $iso): array
    {
        if ($this->calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian(new DateTimeImmutable($iso, new DateTimeZone('UTC')));

            return [$e['year'], min($e['month'], 12)];
        }

        return [(int) substr($iso, 0, 4), (int) substr($iso, 5, 2)];
    }

    /** @param array{0: int, 1: int} $m @return array{0: int, 1: int} */
    private function shift(array $m, int $k): array
    {
        $i = $m[0] * 12 + ($m[1] - 1) + $k;
        $y = intdiv($i, 12) - ($i % 12 < 0 ? 1 : 0);

        return [$y, (($i % 12) + 12) % 12 + 1];
    }

    /** @param array{0: int, 1: int} $m */
    private function monthStart(array $m): string
    {
        return $this->calendar === 'ethiopian'
            ? EthiopianCalendar::toGregorian($m[0], $m[1], 1)->format('Y-m-d')
            : sprintf('%04d-%02d-01', $m[0], $m[1]);
    }

    /** @param array{0: int, 1: int} $m */
    private function monthEnd(array $m): string
    {
        if ($this->calendar === 'ethiopian') {
            return $m[1] === 12
                ? EthiopianCalendar::toGregorian($m[0], 13, EthiopianCalendar::daysInMonth($m[0], 13))->format('Y-m-d')
                : EthiopianCalendar::toGregorian($m[0], $m[1], 30)->format('Y-m-d');
        }

        return (new DateTimeImmutable(sprintf('%04d-%02d-01', $m[0], $m[1])))->modify('last day of this month')->format('Y-m-d');
    }

    /** @param array{0: int, 1: int} $first @return array{from: string, to: string} */
    private function span(array $first, int $months): array
    {
        return ['from' => $this->monthStart($first), 'to' => $this->monthEnd($this->shift($first, $months - 1))];
    }

    /** The same day k months away, in the company's calendar, kept inside the target month. */
    private function shiftDay(string $iso, int $k): string
    {
        if ($this->calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian(new DateTimeImmutable($iso, new DateTimeZone('UTC')));
            $day = $e['month'] === 13 ? 30 : $e['day'];
            [$y, $m] = $this->shift([$e['year'], min($e['month'], 12)], $k);

            return EthiopianCalendar::toGregorian($y, $m, min($day, 30))->format('Y-m-d');
        }
        [$y, $m] = $this->shift([(int) substr($iso, 0, 4), (int) substr($iso, 5, 2)], $k);
        $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');

        return sprintf('%04d-%02d-%02d', $y, $m, min((int) substr($iso, 8, 2), $last));
    }

    private function addDays(string $iso, int $n): string
    {
        return (new DateTimeImmutable($iso, new DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }
}
