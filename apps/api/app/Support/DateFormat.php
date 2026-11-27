<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Tenancy\Models\Company;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * How a company writes dates (Settings, Date format, as QuickBooks offers it): day first by default (27/11/2026), month
 * first (11/27/2026) or ISO (2026-11-27). A company on the Ethiopian calendar sees its own calendar's numbers in the
 * same order (17/03/2019 for Hidar 17, 2019). Used where the API writes a date for people: invoice PDFs, emails, shared
 * messages. Stored dates stay Gregorian "Y-m-d"; the web app has the same rules (apps/web/src/lib/dates.ts).
 */
final class DateFormat
{
    public const FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];

    public const DEFAULT = 'DD/MM/YYYY';

    /** The company's format, or the default when it has none (or an unknown one). */
    public static function of(Company $company): string
    {
        $format = $company->settings['date_format'] ?? null;

        return in_array($format, self::FORMATS, true) ? $format : self::DEFAULT;
    }

    /** A date as the company writes it; null stays null. */
    public static function format(DateTimeInterface|string|null $date, Company $company): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return self::write($day, self::of($company), (string) ($company->calendar ?? 'gregorian'));
    }

    /** "2026-11-27" in a format and calendar: "27/11/2026". */
    public static function write(string $day, string $format, string $calendar = 'gregorian'): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        if ($calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian(new DateTimeImmutable($day, new DateTimeZone('UTC')));
            [$y, $m, $d] = [$e['year'], $e['month'], $e['day']];
        }

        return match ($format) {
            'MM/DD/YYYY' => sprintf('%02d/%02d/%04d', $m, $d, $y),
            'YYYY-MM-DD' => sprintf('%04d-%02d-%02d', $y, $m, $d),
            default => sprintf('%02d/%02d/%04d', $d, $m, $y),
        };
    }
}
