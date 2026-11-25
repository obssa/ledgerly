<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReportPeriods;
use PHPUnit\Framework\TestCase;

/** The same days and answers as apps/web/src/lib/periods.test.ts: the server and the web app must agree. */
final class ReportPeriodsTest extends TestCase
{
    /** @return array{from: ?string, to: ?string} */
    private static function r(string $preset, string $today = '2026-11-26', string $calendar = 'gregorian', int $start = 7, array $years = []): array
    {
        return (new ReportPeriods($today, $calendar, $start, $years))->resolve($preset);
    }

    private static function range(?string $from, ?string $to): array
    {
        return ['from' => $from, 'to' => $to];
    }

    public function test_gregorian_presets_on_thursday_26_november_2026_with_a_july_fiscal_year(): void
    {
        $expected = [
            'all' => [null, null], 'today' => ['2026-11-26', '2026-11-26'], 'yesterday' => ['2026-11-25', '2026-11-25'],
            'this_week' => ['2026-11-23', '2026-11-29'], 'this_week_to_date' => ['2026-11-23', '2026-11-26'], 'last_week' => ['2026-11-16', '2026-11-22'],
            'last_week_to_date' => ['2026-11-16', '2026-11-19'], 'next_week' => ['2026-11-30', '2026-12-06'], 'next_4_weeks' => ['2026-11-26', '2026-12-23'],
            'this_month' => ['2026-11-01', '2026-11-30'], 'this_month_to_date' => ['2026-11-01', '2026-11-26'], 'last_month' => ['2026-10-01', '2026-10-31'],
            'last_month_to_date' => ['2026-10-01', '2026-10-26'], 'next_month' => ['2026-12-01', '2026-12-31'],
            'this_quarter' => ['2026-10-01', '2026-12-31'], 'this_quarter_to_date' => ['2026-10-01', '2026-11-26'], 'last_quarter' => ['2026-07-01', '2026-09-30'],
            'last_quarter_to_date' => ['2026-07-01', '2026-08-26'], 'next_quarter' => ['2027-01-01', '2027-03-31'],
            'this_year' => ['2026-01-01', '2026-12-31'], 'this_year_to_date' => ['2026-01-01', '2026-11-26'], 'this_year_to_last_month' => ['2026-01-01', '2026-10-31'],
            'last_year' => ['2025-01-01', '2025-12-31'], 'last_year_to_date' => ['2025-01-01', '2025-11-26'], 'next_year' => ['2027-01-01', '2027-12-31'],
            'this_fiscal_year' => ['2026-07-01', '2027-06-30'], 'this_fiscal_year_to_date' => ['2026-07-01', '2026-11-26'], 'this_fiscal_year_to_last_month' => ['2026-07-01', '2026-10-31'],
            'last_fiscal_year' => ['2025-07-01', '2026-06-30'], 'last_fiscal_year_to_date' => ['2025-07-01', '2025-11-26'],
            'this_fiscal_quarter' => ['2026-10-01', '2026-12-31'], 'this_fiscal_quarter_to_date' => ['2026-10-01', '2026-11-26'], 'last_fiscal_quarter' => ['2026-07-01', '2026-09-30'],
            'last_7_days' => ['2026-11-20', '2026-11-26'], 'last_30_days' => ['2026-10-28', '2026-11-26'], 'last_90_days' => ['2026-08-29', '2026-11-26'],
            'last_365_days' => ['2025-11-27', '2026-11-26'], 'last_12_months' => ['2025-11-01', '2026-10-31'],
            'since_30_days_ago' => ['2026-10-27', null], 'since_60_days_ago' => ['2026-09-27', null], 'since_90_days_ago' => ['2026-08-28', null], 'since_365_days_ago' => ['2025-11-26', null],
        ];
        $this->assertSame(ReportPeriods::PRESETS, array_keys($expected), 'every preset is checked');
        foreach ($expected as $preset => [$from, $to]) {
            $this->assertSame(self::range($from, $to), self::r($preset), $preset);
        }
    }

    public function test_gregorian_edges(): void
    {
        $this->assertSame(self::range('2026-02-01', '2026-02-28'), self::r('last_month_to_date', '2026-03-31'));
        $this->assertSame(self::range('2027-01-01', '2027-02-28'), self::r('last_year_to_date', '2028-02-29'));
        $this->assertSame(self::range('2025-01-01', '2025-12-31'), self::r('this_year_to_last_month', '2026-01-15'));
        $this->assertSame(self::range('2025-07-01', '2026-06-30'), self::r('this_fiscal_year_to_last_month', '2026-07-03'));
        $this->assertSame(self::range('2026-03-01', '2026-12-31'), self::r('this_fiscal_year', '2026-11-26', 'gregorian', 7, [['starts_on' => '2026-03-01', 'ends_on' => '2026-12-31']]));
    }

    public function test_ethiopian_calendar_hidar_17_2019_with_the_tax_year_from_hamle(): void
    {
        $eth = fn (string $p, string $today = '2026-11-26') => self::r($p, $today, 'ethiopian', 11);
        $this->assertSame(self::range('2026-11-10', '2026-12-09'), $eth('this_month'));         // Hidar 2019
        $this->assertSame(self::range('2026-10-11', '2026-11-09'), $eth('last_month'));         // Tikimt 2019
        $this->assertSame(self::range('2026-09-11', '2026-12-09'), $eth('this_quarter'));
        $this->assertSame(self::range('2026-06-08', '2026-09-10'), $eth('last_quarter'));       // Sene to Nehase + Pagume 2018
        $this->assertSame(self::range('2026-09-11', '2027-09-11'), $eth('this_year'));          // 2019 has a six-day Pagume
        $this->assertSame(self::range('2026-07-08', '2027-07-07'), $eth('this_fiscal_year'));
        $this->assertSame(self::range('2025-07-08', '2026-07-07'), $eth('last_fiscal_year'));
        $this->assertSame(self::range('2026-10-11', '2027-01-08'), $eth('this_fiscal_quarter'));
        $this->assertSame(self::range('2026-07-08', '2026-10-10'), $eth('last_fiscal_quarter'));
        $this->assertSame(self::range('2026-08-07', '2026-09-10'), $eth('this_month', '2026-09-08')); // Pagume 3, 2018
    }
}
