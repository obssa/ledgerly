<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Sales\PaymentTerms;
use PHPUnit\Framework\TestCase;

/** The same dates and answers as apps/web/src/lib/terms.test.ts: the server and the web app must agree. */
final class PaymentTermsTest extends TestCase
{
    private static function days(int $days): array
    {
        return ['kind' => 'days', 'days' => $days];
    }

    private static function dom(int $day, int $within = 0): array
    {
        return ['kind' => 'day_of_month', 'day_of_month' => $day, 'next_month_within' => $within];
    }

    public function test_days_terms_add_the_days(): void
    {
        $this->assertSame('2026-11-02', PaymentTerms::dueDate('2026-11-02', self::days(0), 'gregorian'));   // Due on receipt
        $this->assertSame('2026-12-02', PaymentTerms::dueDate('2026-11-02', self::days(30), 'gregorian'));
        $this->assertSame('2027-01-01', PaymentTerms::dueDate('2026-11-02', self::days(60), 'ethiopian'));   // days are days in any calendar
    }

    public function test_a_day_of_the_month_in_the_gregorian_calendar(): void
    {
        $this->assertSame('2026-02-28', PaymentTerms::dueDate('2026-02-10', self::dom(31), 'gregorian'));   // the last day, whatever the month
        $this->assertSame('2028-02-29', PaymentTerms::dueDate('2028-02-10', self::dom(31), 'gregorian'));
        $this->assertSame('2026-11-30', PaymentTerms::dueDate('2026-11-30', self::dom(31), 'gregorian'));   // dated on it: due that day
        $this->assertSame('2026-11-10', PaymentTerms::dueDate('2026-11-05', self::dom(10), 'gregorian'));
        $this->assertSame('2026-12-10', PaymentTerms::dueDate('2026-11-12', self::dom(10), 'gregorian'));   // past it: next month
        $this->assertSame('2027-01-10', PaymentTerms::dueDate('2026-12-15', self::dom(10), 'gregorian'));   // and next year
        $this->assertSame('2026-12-10', PaymentTerms::dueDate('2026-11-06', self::dom(10, 5), 'gregorian'));   // within 5 days of it
        $this->assertSame('2026-11-10', PaymentTerms::dueDate('2026-11-05', self::dom(10, 5), 'gregorian'));   // 5 days before: this month
        $this->assertSame('2026-02-28', PaymentTerms::dueDate('2026-02-03', self::dom(30), 'gregorian'));
    }

    public function test_a_day_of_the_month_in_the_ethiopian_calendar_with_pagume_counted_with_nehase(): void
    {
        $this->assertSame('2026-11-09', PaymentTerms::dueDate('2026-11-02', self::dom(31), 'ethiopian'));   // Tikimt 23 → Tikimt 30
        $this->assertSame('2026-09-10', PaymentTerms::dueDate('2026-08-20', self::dom(31), 'ethiopian'));   // Nehase 14 → Pagume 5
        $this->assertSame('2026-09-05', PaymentTerms::dueDate('2026-08-20', self::dom(30), 'ethiopian'));   // → Nehase 30
        $this->assertSame('2026-09-20', PaymentTerms::dueDate('2026-09-07', self::dom(10), 'ethiopian'));   // Pagume 2 → Meskerem 10, 2019
        $this->assertSame('2026-11-19', PaymentTerms::dueDate('2026-11-02', self::dom(10), 'ethiopian'));   // past Tikimt 10 → Hidar 10
    }
}
