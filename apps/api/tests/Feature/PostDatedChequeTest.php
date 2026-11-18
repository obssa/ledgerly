<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 5: post-dated cheques (062). A customer's cheque dated next week is held, not booked; on its date it is
 * presented and becomes a cheque received (the customer's credit until allocated). A cheque written to a supplier
 * becomes a cheque paid, its number used once. Another company's customer is not found; nothing posts while held.
 */
final class PostDatedChequeTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_post_dated_cheque_is_held_then_presented_as_a_payment_on_its_date(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
        $cheque = fn (array $over = []) => [...['direction' => 'in', 'contact_id' => $c['contact'], 'account_id' => $c['bank'], 'cheque_number' => 'CH-77',
            'drawn_on' => 'Dashen Bank', 'handed_on' => '2026-11-10', 'cheque_date' => '2026-11-20', 'currency' => 'ETB', 'amount' => '500'], ...$over];
        $entries = fn () => $this->owner()->table('journal_entries')->where('company_id', $c['company'])->count();
        $before = $entries();

        $this->postJson('/api/v1/post-dated-cheques', $cheque(['cheque_date' => '2026-11-10']), $h)->assertUnprocessable()->assertJsonValidationErrors('cheque_date');
        $this->postJson('/api/v1/post-dated-cheques', $cheque(['contact_id' => $b['contact']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $id = $this->postJson('/api/v1/post-dated-cheques', $cheque(), $h)->assertCreated()->assertJsonPath('status', 'held')->json('id');
        $this->assertSame($before, $entries(), 'a held cheque is not in the books');
        $list = $this->getJson('/api/v1/post-dated-cheques', $h)->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertSame([false, 'Dashen Bank'], [$row['due'], $row['drawn_on']]);
        $this->assertSame('600.00', collect($list->json('held'))->firstWhere('direction', 'in')['amount']);   // with the fixture's 100.00

        // Not before its date; on it, a cheque received for 500.00 that the customer can apply to their invoices.
        $this->postJson("/api/v1/post-dated-cheques/{$id}/present", [], $h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->travelTo(Carbon::parse('2026-11-21 12:00'));
        $this->assertTrue(collect($this->getJson('/api/v1/post-dated-cheques', $h)->json('data'))->firstWhere('id', $id)['due']);
        $payment = $this->postJson("/api/v1/post-dated-cheques/{$id}/present", ['date' => '2026-11-21'], $h)->assertOk()
            ->assertJsonPath('status', 'presented')->assertJsonPath('closed_on', '2026-11-21')->json('payment_id');
        $this->getJson("/api/v1/payments-received/{$payment}", $h)->assertOk()->assertJsonPath('method', 'cheque')->assertJsonPath('cheque_number', 'CH-77')
            ->assertJsonPath('amount', '500.00')->assertJsonPath('payment_date', '2026-11-21');
        $this->assertSame($before + 1, $entries());
        $this->postJson("/api/v1/post-dated-cheques/{$id}/present", [], $h)->assertUnprocessable();

        // One written to the supplier: numbered once on the account, then paid on its date.
        $out = fn (array $over = []) => $cheque(['direction' => 'out', 'contact_id' => $c['supplier'], 'cheque_number' => '880001', 'handed_on' => '2026-11-21',
            'cheque_date' => '2026-11-28', 'amount' => '300', ...$over]);
        $this->postJson('/api/v1/post-dated-cheques', $out(['contact_id' => $c['contact']]), $h)->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $written = $this->postJson('/api/v1/post-dated-cheques', $out(), $h)->assertCreated()->json('id');
        $this->postJson('/api/v1/post-dated-cheques', $out(['amount' => '50']), $h)->assertUnprocessable()->assertJsonValidationErrors('cheque_number');
        $this->travelTo(Carbon::parse('2026-11-28 12:00'));
        $paid = $this->postJson("/api/v1/post-dated-cheques/{$written}/present", [], $h)->assertOk()->json('payment_id');
        $this->getJson("/api/v1/payments-made/{$paid}", $h)->assertOk()->assertJsonPath('cheque_number', '880001')->assertJsonPath('amount', '300.00');

        // Cancelled with a reason: it never becomes a payment.
        $this->postJson("/api/v1/post-dated-cheques/{$c['post_dated_cheque']}/cancel", [], $h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/post-dated-cheques/{$c['post_dated_cheque']}/cancel", ['reason' => 'Returned to the customer'], $h)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(['post_dated_cheque_cancelled', 'post_dated_cheque_presented', 'post_dated_cheque_recorded'],
            $this->owner()->table('audit_log')->where('company_id', $c['company'])->where('action', 'like', 'post_dated_cheque_%')->distinct()->orderBy('action')->pluck('action')->all());
    }
}
