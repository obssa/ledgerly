<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Ethiopian calendar (Amete Mihret era) to and from Gregorian, by Julian Day Number: exact integer maths.
 * Twelve months of 30 days plus Pagume (month 13) of 5 days, 6 when the Ethiopian year divided by 4 leaves 3.
 * Dates are always STORED in Gregorian; this class is only for display and for fiscal-period boundaries.
 */
final class EthiopianCalendar
{
    public const MONTHS = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

    private const EPOCH = 1723856; // Julian Day Number of the day before 1 Meskerem, year 0

    /** @return array{year: int, month: int, day: int} */
    public static function fromGregorian(DateTimeImmutable $date): array
    {
        $r = (self::julianDay($date) - self::EPOCH) % 1461;
        $n = ($r % 365) + 365 * intdiv($r, 1460);

        return [
            'year' => 4 * intdiv(self::julianDay($date) - self::EPOCH, 1461) + intdiv($r, 365) - intdiv($r, 1460),
            'month' => intdiv($n, 30) + 1,
            'day' => ($n % 30) + 1,
        ];
    }

    public static function toGregorian(int $year, int $month, int $day): DateTimeImmutable
    {
        if ($month < 1 || $month > 13 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            throw new InvalidArgumentException("Not an Ethiopian date: {$year}-{$month}-{$day}");
        }

        $jdn = self::EPOCH + 365 + 365 * ($year - 1) + intdiv($year, 4) + 30 * $month + $day - 31;

        // Julian Day Number to Gregorian (Fliegel and Van Flandern).
        $l = $jdn + 68569;
        $n = intdiv(4 * $l, 146097);
        $l -= intdiv(146097 * $n + 3, 4);
        $i = intdiv(4000 * ($l + 1), 1461001);
        $l = $l - intdiv(1461 * $i, 4) + 31;
        $j = intdiv(80 * $l, 2447);
        $d = $l - intdiv(2447 * $j, 80);
        $l = intdiv($j, 11);
        $m = $j + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), new DateTimeZone('UTC'));
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return $month <= 12 ? 30 : ($year % 4 === 3 ? 6 : 5);
    }

    /** "Tikimt 23, 2019": English month names, digits only (documents and numbering are English). */
    public static function format(DateTimeImmutable $date): string
    {
        $e = self::fromGregorian($date);

        return sprintf('%s %d, %d', self::MONTHS[$e['month'] - 1], $e['day'], $e['year']);
    }

    private static function julianDay(DateTimeImmutable $date): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date->format('Y-n-j')));
        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;

        return $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
    }
}
