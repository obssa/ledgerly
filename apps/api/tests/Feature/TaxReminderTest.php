<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TaxReturnDue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 7: reminders of tax returns that are due (054), from the filing calendar of the country pack. */
final class TaxReminderTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_people_who_file_tax_are_reminded_once_before_and_once_after_the_due_date(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $owner = $this->lastUser;
        $accountant = $this->createUser('Accountant', 'accountant@example.test');
        $viewer = $this->createUser('Viewer', 'viewer@example.test', withAuthenticator: false);
        $this->owner()->table('memberships')->insert([
            ['company_id' => $c['company'], 'user_id' => $accountant->getKey(), 'role' => 'accountant', 'status' => 'active'],
            ['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active'],
        ]);
        Notification::fake();

        // November's VAT and withholding are due on 30 December (the pack's 30 days): due soon from the 20th.
        Carbon::setTestNow(Carbon::parse('2026-12-21 06:00:00', 'UTC'));
        $this->artisan('ledgerly:send-tax-reminders')->assertSuccessful();
        $this->artisan('ledgerly:send-tax-reminders')->assertSuccessful();   // once
        $sentTo = fn (User $u, string $stage) => Notification::sent($u, TaxReturnDue::class, fn (TaxReturnDue $n) => $n->stage === $stage && $n->periodStart === '2026-11-01')->count();
        $this->assertSame(2, $sentTo($owner, 'due_soon'), 'VAT and withholding, once each');
        $this->assertSame(2, $sentTo($accountant, 'due_soon'));
        Notification::assertNothingSentTo($viewer);
        $this->assertSame(0, Notification::sent($owner, TaxReturnDue::class, fn (TaxReturnDue $n) => $n->periodStart < '2026-11-01')->count(),
            'months long overdue before the company began are not sent');

        // November's VAT is filed; on 31 December only withholding is overdue.
        Carbon::setTestNow(Carbon::parse('2026-12-29 09:00:00', 'UTC'));
        $this->postJson('/api/v1/tax-returns', ['kind' => 'vat', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'filed_on' => '2026-12-29'], $h)->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-12-31 06:00:00', 'UTC'));
        $this->artisan('ledgerly:send-tax-reminders')->assertSuccessful();
        $overdue = Notification::sent($owner, TaxReturnDue::class, fn (TaxReturnDue $n) => $n->stage === 'overdue');
        $this->assertSame(['withholding'], $overdue->map(fn (TaxReturnDue $n) => $n->kind)->unique()->values()->all());
        $this->assertSame(3, $this->owner()->table('tax_reminders')->where('company_id', $c['company'])->count());

        // Turned off in Settings: nothing more.
        $version = $this->getJson('/api/v1/company', $h)->assertOk()->assertJsonPath('tax_reminders', true)->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'tax_reminders' => false], $h)->assertOk()->assertJsonPath('tax_reminders', false);
        Carbon::setTestNow(Carbon::parse('2027-01-21 06:00:00', 'UTC'));
        $this->artisan('ledgerly:send-tax-reminders')->assertSuccessful();
        $this->assertSame(3, $this->owner()->table('tax_reminders')->where('company_id', $c['company'])->count());
    }
}
