<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Models\PaymentTerm;
use App\Support\EthiopianCalendar;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * QuickBooks' Terms (database/schema/109_payment_terms.sql): the due date a term gives a document from its date. The
 * web app works it out the same way while a person fills the form (lib/terms.ts); the two are tested on the same cases.
 *
 * - Days ("Net 30"): the date plus the days.
 * - Day of the month ("Due end of month", "Due on the 10th"): that day of the document's month, in the company's
 *   calendar, or of the next month when the document is dated after it or within next_month_within days of it. Day 31
 *   is the month's last day, whatever its length. In the Ethiopian calendar Pagume counts with Nehase, as the reports'
 *   months do: Nehase's last day is Pagume's last.
 */
final class PaymentTerms
{
    /** Every company starts with these (the database gave existing companies the same, 109). */
    public const DEFAULTS = [
        ['name' => 'Due on receipt', 'kind' => 'days', 'days' => 0, 'day_of_month' => null],
        ['name' => 'Net 15', 'kind' => 'days', 'days' => 15, 'day_of_month' => null],
        ['name' => 'Net 30', 'kind' => 'days', 'days' => 30, 'day_of_month' => null],
        ['name' => 'Net 60', 'kind' => 'days', 'days' => 60, 'day_of_month' => null],
        ['name' => 'Due end of month', 'kind' => 'day_of_month', 'days' => null, 'day_of_month' => 31],
    ];

    /**
     * @param array{kind: string, days?: int|null, day_of_month?: int|null, next_month_within?: int|null}|object $term
     * @param 'gregorian'|'ethiopian'|string $calendar
     */
    public static function dueDate(string $issue, array|object $term, string $calendar): string
    {
        $t = (array) $term;
        $date = new DateTimeImmutable($issue, new DateTimeZone('UTC'));
        if ($t['kind'] === 'days') {
            return $date->modify('+' . (int) $t['days'] . ' days')->format('Y-m-d');
        }

        $want = (int) $t['day_of_month'];
        $within = (int) ($t['next_month_within'] ?? 0);
        [$y, $m, $d] = self::position($date, $calendar);
        // 31 is the last day: Nehase's, with Pagume, is its 35th or 36th.
        $dayIn = fn (int $y, int $m): int => $want === 31 ? self::length($y, $m, $calendar) : min($want, self::length($y, $m, $calendar));
        $due = $dayIn($y, $m);
        if ($due < $d || $due - $d < $within) {
            [$y, $m] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
            $due = $dayIn($y, $m);
        }

        return self::date($y, $m, $due, $calendar);
    }

    /** A contact's documents dated $issue fall due: by its term, else by its days (a contact saved before 109). */
    public static function forContact(?string $contactId, string $issue, string $calendar): string
    {
        $contact = $contactId === null ? null : DB::table('contacts')->where('id', $contactId)->first(['payment_terms_id', 'payment_terms_days']);
        $term = $contact?->payment_terms_id ? PaymentTerm::query()->find($contact->payment_terms_id) : null;

        return self::dueDate($issue, $term?->only(['kind', 'days', 'day_of_month', 'next_month_within'])
            ?? ['kind' => 'days', 'days' => (int) ($contact->payment_terms_days ?? 0)], $calendar);
    }

    /** @return array{0: int, 1: int, 2: int} year, month 1-12 and day in it, in the company's calendar */
    private static function position(DateTimeImmutable $date, string $calendar): array
    {
        if ($calendar === 'ethiopian') {
            $e = EthiopianCalendar::fromGregorian($date);

            return $e['month'] === 13 ? [$e['year'], 12, 30 + $e['day']] : [$e['year'], $e['month'], $e['day']];
        }

        return [(int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j')];
    }

    private static function length(int $y, int $m, string $calendar): int
    {
        if ($calendar === 'ethiopian') {
            return $m === 12 ? 30 + EthiopianCalendar::daysInMonth($y, 13) : 30;
        }

        return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
    }

    private static function date(int $y, int $m, int $d, string $calendar): string
    {
        if ($calendar === 'ethiopian') {
            return ($d > 30 ? EthiopianCalendar::toGregorian($y, 13, $d - 30) : EthiopianCalendar::toGregorian($y, $m, $d))->format('Y-m-d');
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
