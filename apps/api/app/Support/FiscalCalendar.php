<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fiscal years and their twelve periods for a company, in the Gregorian or the Ethiopian calendar.
 * Boundaries are returned as Gregorian dates (that is how dates are stored), so reports and period
 * locks work the same way for both calendars.
 *
 * Ethiopian calendar: the year starts on day 1 of the company's start month (Hamle, month 11, for the
 * tax year). Pagume, the 5 or 6 day 13th month, is reported together with Nehase (month 12), so a year
 * always has twelve periods.
 */
final class FiscalCalendar
{
    public function __construct(
        public readonly string $calendar,   // 'gregorian' | 'ethiopian'
        public readonly int $startMonth,    // 1-12, in the company's calendar
    ) {
    }

    /** @return array{start: DateTimeImmutable, end: DateTimeImmutable, label: string} the fiscal year containing $date */
    public function yearContaining(DateTimeImmutable $date): array
    {
        $date = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));

        if ($this->calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian($date);
            // Pagume belongs with Nehase (12) for this comparison.
            $year = min($e['month'], 12) >= $this->startMonth ? $e['year'] : $e['year'] - 1;
            $start = EthiopianCalendar::toGregorian($year, $this->startMonth, 1);
            $end = EthiopianCalendar::toGregorian($year + 1, $this->startMonth, 1)->modify('-1 day');
            $label = $this->startMonth === 1 ? "{$year} E.C." : sprintf('%d/%02d E.C.', $year, ($year + 1) % 100);

            return ['start' => $start, 'end' => $end, 'label' => $label];
        }

        $year = (int) $date->format('n') >= $this->startMonth ? (int) $date->format('Y') : (int) $date->format('Y') - 1;
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $this->startMonth), new DateTimeZone('UTC'));
        $end = $start->modify('+1 year -1 day');
        $label = $this->startMonth === 1 ? (string) $year : sprintf('%d/%02d', $year, ($year + 1) % 100);

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    /**
     * The twelve periods of the fiscal year containing $date.
     *
     * @return list<array{number: int, start: DateTimeImmutable, end: DateTimeImmutable, label: string}>
     */
    public function periods(DateTimeImmutable $date): array
    {
        $year = $this->yearContaining($date);
        $periods = [];

        if ($this->calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian($year['start']);
            [$y, $m] = [$e['year'], $e['month']];
            for ($i = 1; $i <= 12; $i++) {
                $start = EthiopianCalendar::toGregorian($y, $m, 1);
                $lastMonth = $m === 12 ? 13 : $m; // Nehase runs on through Pagume
                $end = EthiopianCalendar::toGregorian($y, $lastMonth, EthiopianCalendar::daysInMonth($y, $lastMonth));
                $label = EthiopianCalendar::MONTHS[$m - 1] . ($m === 12 ? ' and Pagume' : '') . " {$y}";
                $periods[] = ['number' => $i, 'start' => $start, 'end' => $end, 'label' => $label];
                [$y, $m] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
            }

            return $periods;
        }

        for ($i = 1; $i <= 12; $i++) {
            $start = $year['start']->modify('+' . ($i - 1) . ' months');
            $periods[] = ['number' => $i, 'start' => $start, 'end' => $start->modify('last day of this month'), 'label' => $start->format('F Y')];
        }

        return $periods;
    }
}
