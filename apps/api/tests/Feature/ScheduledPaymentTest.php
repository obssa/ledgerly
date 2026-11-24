<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ScheduledPaymentDue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: scheduled supplier payments (103). A payment planned for the 20th for two of a supplier's bills is not in
 * the books; it can be moved and changed while scheduled; on its day the person who planned it is emailed once; it is
 * recorded only when someone confirms the money went out (with the tax withheld on a certificate), and then the bills
 * are paid. A bill is planned once at a time; a cancelled plan keeps its reason; another company never sees it.
 */
final class ScheduledPaymentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_payment_is_scheduled_reminded_of_on_its_day_and_recorded_only_when_confirmed(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $bUser = $this->lastUser;
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $owner = $this->lastUser;
        $viewer = $this->createUser('Viewer', 'viewer@example.test', withAuthenticator: false);
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $h = ['X-Company-Id' => $c['company']];
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'UTC'));
        $bill = function (string $ref, string $price) use ($c, $h): string {
            $id = $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-05', 'currency' => 'ETB', 'reference' => $ref,
                'lines' => [['description' => 'Supplies', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $c['rent']]]], $h)->assertCreated()->json('id');
            $this->postJson("/api/v1/bills/{$id}/approve", [], $h)->assertOk();

            return $id;
        };
        $b1 = $c['posted_bill'];   // 400.00
        $b2 = $bill('R-200', '300');
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();
        $before = $entries();
        $plan = fn (array $over = []) => [...['contact_id' => $c['supplier'], 'account_id' => $c['bank'], 'currency' => 'ETB', 'pay_on' => '2026-11-20',
            'method' => 'bank_transfer', 'reference' => 'November suppliers', 'bills' => [['bill_id' => $b1], ['bill_id' => $b2, 'amount' => '200']]], ...$over];

        // Refused: a day gone by, another company's supplier, an invoice, more than is owed.
        $this->postJson('/api/v1/scheduled-payments', $plan(['pay_on' => '2026-11-09']), $h)->assertUnprocessable()->assertJsonValidationErrors('pay_on');
        $this->postJson('/api/v1/scheduled-payments', $plan(['contact_id' => $b['supplier']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson('/api/v1/scheduled-payments', $plan(['bills' => [['bill_id' => $c['invoice']]]]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills.0.bill_id');
        $this->postJson('/api/v1/scheduled-payments', $plan(['bills' => [['bill_id' => $b2, 'amount' => '300.01']]]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills.0.amount');
        $this->postJson('/api/v1/scheduled-payments', $plan(['bills' => []]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills');

        // Scheduled: 400.00 (all of the first bill) and 200.00 of the second. Nothing in the books.
        $id = $this->postJson('/api/v1/scheduled-payments', $plan(), $h)->assertCreated()
            ->assertJsonPath('status', 'scheduled')->assertJsonPath('amount', '600.00')->assertJsonPath('bill_count', 2)->assertJsonPath('due', false)
            ->assertJsonPath('bills.0.amount', '400.00')->json('id');
        $this->assertSame($before, $entries(), 'a scheduled payment is not in the books');
        $this->postJson('/api/v1/scheduled-payments', $plan(['bills' => [['bill_id' => $b1]]]), $h)->assertUnprocessable()->assertJsonValidationErrors('bills.0.bill_id');
        $this->getJson("/api/v1/bills/{$b1}", $h)->assertOk()->assertJsonPath('scheduled_payment.pay_on', '2026-11-20')->assertJsonPath('scheduled_payment.id', $id);

        // Moved to the 21st, all of the second bill.
        $this->putJson("/api/v1/scheduled-payments/{$id}", ['account_id' => $c['bank'], 'pay_on' => '2026-11-21', 'method' => 'bank_transfer',
            'bills' => [['bill_id' => $b1], ['bill_id' => $b2]]], $h)->assertOk()->assertJsonPath('amount', '700.00')->assertJsonPath('pay_on', '2026-11-21');
        $changed = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'scheduled_payment_changed', 'subject_id' => $id])->first();
        $this->assertSame(['2026-11-21', null], [json_decode($changed->after, true)['pay_on'], json_decode($changed->after, true)['reference'] ?? null]);

        // The 20th: not due yet, nobody emailed. The 21st (in Addis Ababa): the owner who planned it, once.
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-20 06:00', 'UTC'));
        $this->artisan('ledgerly:remind-scheduled-payments')->assertSuccessful();
        Notification::assertNothingSent();
        $this->travelTo(Carbon::parse('2026-11-21 04:00', 'UTC'));
        $this->artisan('ledgerly:remind-scheduled-payments')->assertSuccessful();
        $this->artisan('ledgerly:remind-scheduled-payments')->assertSuccessful();
        $sent = Notification::sent($owner, ScheduledPaymentDue::class);
        $this->assertCount(1, $sent);
        $this->assertSame(['700.00', '2026-11-21'], [$sent->first()->amount, $sent->first()->payOn]);
        Notification::assertNothingSentTo($viewer);

        $list = $this->getJson('/api/v1/scheduled-payments', $h)->assertOk();
        $this->assertTrue(collect($list->json('data'))->firstWhere('id', $id)['due']);
        $this->assertSame(['700.00', 1], [$list->json('totals.0.due'), $list->json('totals.0.due_count')]);
        $this->assertSame($before, $entries(), 'still nothing in the books until someone confirms');

        // Confirmed: 2% withheld needs its certificate; then a payment of 686.00 plus 14.00 withheld, and both bills paid.
        $this->postJson("/api/v1/scheduled-payments/{$id}/confirm", ['withholding' => '14'], $h)->assertUnprocessable()->assertJsonValidationErrors('withholding_certificate');
        $this->postJson("/api/v1/scheduled-payments/{$id}/confirm", ['payment_date' => '2026-11-22'], $h)->assertUnprocessable()->assertJsonValidationErrors('payment_date');
        $payment = $this->postJson("/api/v1/scheduled-payments/{$id}/confirm", ['withholding' => '14', 'withholding_certificate' => 'WH-0091', 'reference' => 'TRF-5531'], $h)->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('closed_on', '2026-11-21')->json('payment_id');
        $this->getJson("/api/v1/payments-made/{$payment}", $h)->assertOk()->assertJsonPath('amount', '686.00')->assertJsonPath('withholding', '14.00')
            ->assertJsonPath('reference', 'TRF-5531')->assertJsonPath('payment_date', '2026-11-21');
        $this->getJson("/api/v1/bills/{$b1}", $h)->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('scheduled_payment', null);
        $this->getJson("/api/v1/bills/{$b2}", $h)->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame($before + 1, $entries());
        $this->postJson("/api/v1/scheduled-payments/{$id}/confirm", [], $h)->assertUnprocessable();
        $this->putJson("/api/v1/scheduled-payments/{$id}", ['account_id' => $c['bank'], 'pay_on' => '2026-11-25', 'method' => 'cash', 'bills' => [['bill_id' => $b2]]], $h)
            ->assertUnprocessable();

        // Another plan, cancelled with a reason: it never becomes a payment.
        $b3 = $bill('R-300', '50');
        $other = $this->postJson('/api/v1/scheduled-payments', $plan(['pay_on' => '2026-11-30', 'bills' => [['bill_id' => $b3]]]), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/scheduled-payments/{$other}/cancel", [], $h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/scheduled-payments/{$other}/cancel", ['reason' => 'Paid in cash at the shop'], $h)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson('/api/v1/scheduled-payments', $plan(['pay_on' => '2026-11-30', 'bills' => [['bill_id' => $b3]]]), $h)->assertCreated();   // free again
        $this->assertSame(['payment_scheduled', 'scheduled_payment_cancelled', 'scheduled_payment_changed', 'scheduled_payment_confirmed'],
            $this->owner()->table('audit_log')->where('company_id', $c['company'])->whereIn('action', ['payment_scheduled', 'scheduled_payment_changed',
                'scheduled_payment_confirmed', 'scheduled_payment_cancelled'])->distinct()->orderBy('action')->pluck('action')->all());
        $this->assertSame('Paid in cash at the shop', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'scheduled_payment_cancelled'])->value('reason'));

        // Another company's people never see it.
        $this->actingAs($bUser, 'sanctum');
        $hb = ['X-Company-Id' => $b['company']];
        $this->getJson("/api/v1/scheduled-payments/{$id}", $hb)->assertNotFound();
        $this->postJson("/api/v1/scheduled-payments/{$other}/cancel", ['reason' => 'Not mine'], $hb)->assertNotFound();
        $this->assertNotContains($id, collect($this->getJson('/api/v1/scheduled-payments', $hb)->assertOk()->json('data'))->pluck('id')->all());
        $this->assertInstanceOf(User::class, $owner);
    }
}
