<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DateFormat;
use PHPUnit\Framework\TestCase;

/** The same answers as apps/web/src/lib/dates.test.ts: the PDF and emails write dates as the screens do. */
final class DateFormatTest extends TestCase
{
    public function test_each_format_in_the_gregorian_calendar(): void
    {
        $this->assertSame('27/11/2026', DateFormat::write('2026-11-27', 'DD/MM/YYYY'));
        $this->assertSame('03/04/2026', DateFormat::write('2026-03-04', 'MM/DD/YYYY'));
        $this->assertSame('2026-11-27', DateFormat::write('2026-11-27', 'YYYY-MM-DD'));
        $this->assertSame('27/11/2026', DateFormat::write('2026-11-27', 'anything else'));
    }

    public function test_the_ethiopian_calendar_writes_its_own_numbers(): void
    {
        $this->assertSame('17/03/2019', DateFormat::write('2026-11-26', 'DD/MM/YYYY', 'ethiopian'));   // Hidar 17, 2019
        $this->assertSame('05/13/2018', DateFormat::write('2026-09-10', 'DD/MM/YYYY', 'ethiopian'));   // Pagume 5, 2018
        $this->assertSame('03/17/2019', DateFormat::write('2026-11-26', 'MM/DD/YYYY', 'ethiopian'));
    }
}
