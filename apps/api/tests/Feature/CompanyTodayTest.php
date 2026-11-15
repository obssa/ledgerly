<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * "Not after today" is today where the company is, not on the server (UTC). At 22:00 UTC on 16 November it is already
 * 17 November in Addis Ababa (UTC+3), the Ethiopian pack's time zone.
 */
final class CompanyTodayTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_payment_dated_today_in_the_companys_time_zone_is_accepted(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->assertSame('Africa/Addis_Ababa', $this->owner()->table('companies')->where('id', $c['company'])->value('timezone'));
        Carbon::setTestNow(Carbon::parse('2026-11-16 22:00:00', 'UTC'));

        $payment = fn (string $date) => ['contact_id' => $c['contact'], 'account_id' => $c['bank'], 'payment_date' => $date,
            'method' => 'cash', 'currency' => 'ETB', 'amount' => '10'];
        $this->postJson('/api/v1/payments-received', $payment('2026-11-17'), $h)->assertCreated();
        $this->postJson('/api/v1/payments-received', $payment('2026-11-18'), $h)->assertUnprocessable()->assertJsonValidationErrors('payment_date');
        $this->getJson('/api/v1/reports/ar-ageing', $h)->assertOk()->assertJsonPath('as_of', '2026-11-17');

        // The same moment for a company kept in UTC is still the 16th.
        $this->owner()->table('companies')->where('id', $c['company'])->update(['timezone' => 'UTC']);
        $this->postJson('/api/v1/payments-received', $payment('2026-11-17'), $h)->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }
}
