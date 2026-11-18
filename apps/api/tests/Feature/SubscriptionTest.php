<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 11: Ledgerly's own subscription, without a payment provider (053): trial, read-only when suspended, the operator's hand. */
final class SubscriptionTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_trial_ends_in_read_only_books_until_the_operator_records_a_payment(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync', 'billing.instructions' => 'Transfer to CBE 1000123456789, quoting your company name.']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $draft = fn () => $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB',
            'lines' => [['description' => 'Coffee', 'quantity' => '1', 'unit_price' => '100', 'account_id' => $c['sales']]]], $h);

        $sub = $this->getJson('/api/v1/subscription', $h)->assertOk()->assertJsonPath('status', 'trial')->assertJsonPath('read_only', false)
            ->assertJsonPath('instructions', 'Transfer to CBE 1000123456789, quoting your company name.')->assertJsonPath('can.manage', true);
        $this->assertGreaterThanOrEqual(29, $sub->json('days_left'));

        // Not enforced: time moves nothing.
        $this->travel(40)->days();
        config(['billing.enforce' => false]);
        $this->artisan('ledgerly:subscriptions-tick')->assertSuccessful();
        $this->getJson('/api/v1/subscription', $h)->assertJsonPath('status', 'trial');

        // Enforced: the trial is over, the books are read-only (they can still be read and exported).
        config(['billing.enforce' => true]);
        $this->artisan('ledgerly:subscriptions-tick')->assertSuccessful();
        $this->getJson('/api/v1/subscription', $h)->assertJsonPath('status', 'suspended')->assertJsonPath('read_only', true);
        $draft()->assertStatus(402)->assertJsonPath('type', 'SubscriptionReadOnly');
        $this->getJson('/api/v1/invoices', $h)->assertOk();

        // The operator records the transfer: active until the date paid for, and the books open again.
        $until = now()->addMonth()->format('Y-m-d');
        $this->artisan('ledgerly:subscription', ['organisation' => $c['company'], '--activate' => true, '--paid-until' => $until, '--plan' => 'essentials',
            '--reference' => 'TRF-2026-0042', '--by' => 'Operator test'])->assertSuccessful();
        $this->getJson('/api/v1/subscription', $h)->assertJsonPath('status', 'active')->assertJsonPath('plan', 'essentials')->assertJsonPath('until', $until);
        $draft()->assertCreated();
        $events = $this->owner()->table('subscription_events')->where('organisation_id', $c['organisation'])->orderBy('id')->get();
        $this->assertSame(['trial_ended', 'payment_recorded'], $events->pluck('action')->all());
        $this->assertSame('TRF-2026-0042', $events[1]->reference);

        // Unpaid past the date, then past the grace days: past due, then suspended.
        $this->travel(32)->days();
        $this->artisan('ledgerly:subscriptions-tick')->assertSuccessful();
        $this->getJson('/api/v1/subscription', $h)->assertJsonPath('status', 'past_due')->assertJsonPath('read_only', false);
        $this->travel(15)->days();
        $this->artisan('ledgerly:subscriptions-tick')->assertSuccessful();
        $this->getJson('/api/v1/subscription', $h)->assertJsonPath('status', 'suspended');

        // The operator's command refuses what makes no sense.
        $this->artisan('ledgerly:subscription', ['organisation' => $c['company'], '--extend-trial' => '10'])->assertFailed();
        $this->artisan('ledgerly:subscription', ['organisation' => $c['company'], '--plan' => 'platinum'])->assertFailed();
        $this->artisan('ledgerly:subscription', ['organisation' => $c['company'], '--activate' => true, '--paid-until' => '2020-01-01'])->assertFailed();
    }

    public function test_a_member_without_settings_sees_the_status_but_not_how_to_pay(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync', 'billing.instructions' => 'Transfer to CBE 1000123456789.']);
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer', 'status' => 'active']);
        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/v1/subscription', ['X-Company-Id' => $c['company']])->assertOk()->assertJsonPath('status', 'trial')
            ->assertJsonPath('instructions', null)->assertJsonPath('can.manage', false);
    }
}
