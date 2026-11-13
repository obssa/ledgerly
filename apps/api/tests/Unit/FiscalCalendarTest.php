<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EthiopianCalendar;
use App\Support\FiscalCalendar;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FiscalCalendarTest extends TestCase
{
    public function test_gregorian_to_ethiopian_matches_known_dates(): void
    {
        // Same cases as apps/web/src/lib/decimal.test.ts.
        $this->assertSame(['year' => 2019, 'month' => 1, 'day' => 1], EthiopianCalendar::fromGregorian(new DateTimeImmutable('2026-09-11')));
        $this->assertSame(['year' => 2019, 'month' => 2, 'day' => 23], EthiopianCalendar::fromGregorian(new DateTimeImmutable('2026-11-02')));
        $this->assertSame(['year' => 2015, 'month' => 13, 'day' => 6], EthiopianCalendar::fromGregorian(new DateTimeImmutable('2023-09-11')));
        $this->assertSame('Tikimt 23, 2019', EthiopianCalendar::format(new DateTimeImmutable('2026-11-02')));
    }

    public function test_every_day_of_ten_years_converts_there_and_back(): void
    {
        $day = new DateTimeImmutable('2020-01-01');
        for ($i = 0; $i < 3653; $i++) {
            $e = EthiopianCalendar::fromGregorian($day);
            $this->assertSame($day->format('Y-m-d'), EthiopianCalendar::toGregorian($e['year'], $e['month'], $e['day'])->format('Y-m-d'));
            $day = $day->modify('+1 day');
        }
    }

    public function test_ethiopian_tax_year_runs_hamle_to_sene(): void
    {
        $fiscal = new FiscalCalendar('ethiopian', 11);
        $year = $fiscal->yearContaining(new DateTimeImmutable('2026-11-13'));

        $this->assertSame('2026-07-08', $year['start']->format('Y-m-d'));  // 1 Hamle 2018
        $this->assertSame('2027-07-07', $year['end']->format('Y-m-d'));    // 30 Sene 2019
        $this->assertSame('2018/19 E.C.', $year['label']);

        // The day before belongs to the year before.
        $this->assertSame('2026-07-07', $fiscal->yearContaining(new DateTimeImmutable('2026-07-07'))['end']->format('Y-m-d'));
    }

    public function test_ethiopian_year_has_twelve_periods_with_pagume_in_nehase(): void
    {
        $periods = (new FiscalCalendar('ethiopian', 11))->periods(new DateTimeImmutable('2026-11-13'));

        $this->assertSame(12, count($periods));
        $this->assertSame('Hamle 2018', $periods[0]['label']);
        $this->assertSame('Nehase and Pagume 2018', $periods[1]['label']);
        $this->assertSame('2026-08-07', $periods[1]['start']->format('Y-m-d'));
        $this->assertSame('2026-09-10', $periods[1]['end']->format('Y-m-d'));   // 5 Pagume 2018
        $this->assertSame('Meskerem 2019', $periods[2]['label']);
        $this->assertSame('2026-09-11', $periods[2]['start']->format('Y-m-d'));
        $this->assertSame('Sene 2019', $periods[11]['label']);

        // Periods are contiguous and cover the whole year.
        for ($i = 1; $i < 12; $i++) {
            $this->assertSame($periods[$i - 1]['end']->modify('+1 day')->format('Y-m-d'), $periods[$i]['start']->format('Y-m-d'));
        }
        $this->assertSame('2027-07-07', $periods[11]['end']->format('Y-m-d'));
    }

    public function test_gregorian_fiscal_year_from_july(): void
    {
        $fiscal = new FiscalCalendar('gregorian', 7);
        $year = $fiscal->yearContaining(new DateTimeImmutable('2026-11-13'));

        $this->assertSame(['2026-07-01', '2027-06-30', '2026/27'], [$year['start']->format('Y-m-d'), $year['end']->format('Y-m-d'), $year['label']]);
        $periods = $fiscal->periods(new DateTimeImmutable('2026-11-13'));
        $this->assertSame('2027-02-28', $periods[7]['end']->format('Y-m-d'));
        $this->assertSame('2027-06-30', $periods[11]['end']->format('Y-m-d'));
        $this->assertSame('2026', (new FiscalCalendar('gregorian', 1))->yearContaining(new DateTimeImmutable('2026-03-01'))['label']);
    }
}
