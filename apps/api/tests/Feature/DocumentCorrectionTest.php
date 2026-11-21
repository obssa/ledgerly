<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Fixing a mistake on an invoice or bill, and who did what. The document says whether it can be corrected in place
 * (and if not, why, in words); its history names who created it, changed the draft, posted it and corrected it,
 * with the reason and the fields that changed; credit notes record their reason. Only roles with
 * transactions.edit may correct a posted document.
 */
final class DocumentCorrectionTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_posted_invoice_is_corrected_with_its_history_named(): void
    {
        config(['einvoicing.force_sandbox' => false, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('hana@example.test', 'Hana Bekele');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $body = fn (string $qty, ?string $reason = null) => array_filter(['reason' => $reason, 'contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting service', 'quantity' => $qty, 'unit_price' => '1000', 'account_id' => $c['sales']]]]);

        // A draft: created, then changed: both recorded, the change field by field.
        $id = $this->postJson('/api/v1/invoices', $body('2'), $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/invoices/{$id}", $body('3'), $h)->assertOk()->assertJsonPath('total', '3000.00');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $h)->assertOk();

        // Posted and unpaid: it can be corrected, nothing in the way.
        $this->getJson("/api/v1/invoices/{$id}", $h)->assertOk()->assertJsonPath('can.correct', true)->assertJsonPath('correct_blocked', null);
        $this->putJson("/api/v1/invoices/{$id}", $body('4', 'One more hour was worked'), $h)->assertOk()->assertJsonPath('total', '4000.00');

        $history = $this->getJson("/api/v1/invoices/{$id}/history", $h)->assertOk();
        $this->assertSame('Hana Bekele', $history->json('versions.0.edited_by_name'));
        $this->assertSame('One more hour was worked', $history->json('versions.0.reason'));
        $audit = collect($history->json('audit'));
        $this->assertSame(['edited_after_posting', 'posted', 'draft_saved', 'created'], $audit->pluck('action')->all());
        $this->assertSame(['Hana Bekele'], $audit->pluck('user_name')->unique()->values()->all());
        $saved = $audit->firstWhere('action', 'draft_saved');
        $this->assertSame('2000.00', json_decode($saved['before'], true)['total']);
        $this->assertSame('3000.00', json_decode($saved['after'], true)['total']);
        $this->assertStringStartsWith('Roasting service × 3', json_decode($saved['after'], true)['lines'][0]);

        // Paid in part: no longer corrected in place; the reason says what to do instead.
        $this->postJson('/api/v1/payments-received', ['contact_id' => $c['contact'], 'account_id' => $c['bank'], 'payment_date' => $today, 'method' => 'bank_transfer',
            'currency' => 'ETB', 'amount' => '1000', 'allocations' => [['invoice_id' => $id, 'amount' => '1000']]], $h)->assertCreated();
        $shown = $this->getJson("/api/v1/invoices/{$id}", $h)->assertOk()->assertJsonPath('can.correct', false);
        $this->assertStringContainsString('issue a credit note', (string) $shown->json('correct_blocked'));
        $this->putJson("/api/v1/invoices/{$id}", $body('5', 'Try anyway'), $h)->assertStatus(422);

        // A credit note records its reason from the start.
        $credit = $this->postJson("/api/v1/invoices/{$id}/credit-notes", ['issue_date' => $today, 'notes' => 'Half an hour charged twice',
            'lines' => [['description' => 'Roasting service', 'quantity' => '0.5', 'unit_price' => '1000', 'account_id' => $c['sales']]]], $h)->assertCreated()->json('id');
        $made = collect($this->getJson("/api/v1/invoices/{$credit}/history", $h)->assertOk()->json('audit'))->firstWhere('action', 'credit_note_created');
        $this->assertSame('Half an hour charged twice', $made['reason']);
        $this->assertSame('Hana Bekele', $made['user_name']);

        // The audit chain is intact.
        $this->assertNull($this->owner()->selectOne('select audit_log_verify(?) as broken', [$c['company']])->broken);
    }

    public function test_only_roles_that_may_correct_see_it_offered_and_are_allowed(): void
    {
        config(['einvoicing.force_sandbox' => false, 'queue.default' => 'sync']);
        $c = $this->companyWithRecords('owner@example.test', 'Owner');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $body = fn (string $qty, ?string $reason = null) => array_filter(['reason' => $reason, 'contact_id' => $c['contact'], 'issue_date' => $today, 'currency' => 'ETB',
            'lines' => [['description' => 'Roasting service', 'quantity' => $qty, 'unit_price' => '1000', 'account_id' => $c['sales']]]]);
        $id = $this->postJson('/api/v1/invoices', $body('1'), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $h)->assertOk();

        $member = function (string $role, array $permissions = []) use ($c) {
            $user = $this->createUser(ucfirst($role), "{$role}" . Str::random(4) . '@example.test');
            $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $user->getKey(), 'role' => $role,
                'permissions' => json_encode($permissions)]);

            return $user;
        };

        // Sales may see the invoice but not correct it: not offered, and refused.
        $this->actingAs($member('sales'), 'sanctum');
        $this->getJson("/api/v1/invoices/{$id}", $h)->assertOk()->assertJsonPath('can.correct', false);
        $this->putJson("/api/v1/invoices/{$id}", $body('2', 'Not mine to change'), $h)->assertForbidden();

        // A custom role with "Correct posted invoices and bills" may.
        $this->actingAs($member('custom', ['invoices.view', 'transactions.edit']), 'sanctum');
        $this->getJson("/api/v1/invoices/{$id}", $h)->assertOk()->assertJsonPath('can.correct', true);
        $this->putJson("/api/v1/invoices/{$id}", $body('2', 'Two hours, not one'), $h)->assertOk()->assertJsonPath('total', '2000.00');
        $this->assertSame('Custom', $this->getJson("/api/v1/invoices/{$id}/history", $h)->json('versions.0.edited_by_name'));
    }

    public function test_a_posted_bill_is_corrected_and_its_history_named(): void
    {
        config(['queue.default' => 'sync']);
        $c = $this->companyWithRecords('meron@example.test', 'Meron Alemu');
        $h = ['X-Company-Id' => $c['company']];
        $today = now((string) ($this->owner()->table('companies')->where('id', $c['company'])->value('timezone') ?: 'UTC'))->format('Y-m-d');
        $body = fn (string $price, ?string $reason = null) => array_filter(['reason' => $reason, 'contact_id' => $c['supplier'], 'reference' => 'SUP-77', 'issue_date' => $today,
            'currency' => 'ETB', 'lines' => [['description' => 'Rent, November', 'quantity' => '1', 'unit_price' => $price, 'account_id' => $c['rent']]]]);

        $id = $this->postJson('/api/v1/bills', $body('5000'), $h)->assertCreated()->json('id');
        $this->putJson("/api/v1/bills/{$id}", $body('5500'), $h)->assertOk()->assertJsonPath('total', '5500.00');
        $this->postJson("/api/v1/bills/{$id}/approve", [], $h)->assertOk();
        $this->getJson("/api/v1/bills/{$id}", $h)->assertOk()->assertJsonPath('can.correct', true)->assertJsonPath('correct_blocked', null);
        $this->putJson("/api/v1/bills/{$id}", $body('6000', 'Rent went up in November'), $h)->assertOk()->assertJsonPath('total', '6000.00');

        $history = $this->getJson("/api/v1/bills/{$id}/history", $h)->assertOk();
        $this->assertSame('Meron Alemu', $history->json('versions.0.edited_by_name'));
        $this->assertSame(['edited_after_posting', 'posted', 'draft_saved', 'created'], collect($history->json('audit'))->pluck('action')->all());
    }
}
