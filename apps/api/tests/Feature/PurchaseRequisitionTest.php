<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: purchase requisitions (084). A salesperson asks for 4 boxes of paper cups (about 350.00 each) for the cafe;
 * they cannot approve it themselves; the approver does, and the purchasing officer orders it from a supplier, as a
 * draft purchase order at the prices they give (the estimate otherwise). A rejection says why; a request is withdrawn by
 * the person who asked; the asker sees only their own, and another company finds none of it.
 */
final class PurchaseRequisitionTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_request_is_approved_by_someone_else_and_ordered(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $member = function (string $role, string $name) use ($c) {
            $user = $this->createUser($name, strtolower(str_replace(' ', '.', $name)) . '@example.test');
            $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

            return $user;
        };
        [$sales, $other, $approver, $buyer, $viewer] = [$member('sales', 'Selam Tesfaye'), $member('sales', 'Dawit Bekele'), $member('approver', 'Abebe Approver'),
            $member('purchasing', 'Pawlos Buyer'), $member('viewer', 'Vera Viewer')];
        $expense = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '6800'])->value('id');
        $ask = ['reason' => 'For the cafe counter', 'lines' => [['description' => 'Paper cups, box of 1,000', 'quantity' => '4', 'estimated_price' => '350'],
            ['description' => 'Napkins', 'quantity' => '10']]];

        $this->actingAs($viewer, 'sanctum');
        $this->postJson('/api/v1/purchase-requisitions', $ask, $h)->assertForbidden();

        $this->actingAs($sales, 'sanctum');
        $this->postJson('/api/v1/purchase-requisitions', ['lines' => $ask['lines']], $h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/purchase-requisitions', [...$ask, 'lines' => [['description' => 'Cups', 'quantity' => '1', 'item_id' => $b['item']]]], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');   // another company's item
        $r = $this->postJson('/api/v1/purchase-requisitions', $ask, $h)->assertCreated()->assertJsonPath('status', 'requested')
            ->assertJsonPath('requested_by_name', 'Selam Tesfaye')->assertJsonPath('can.withdraw', true)->assertJsonPath('can.decide', false)->json('id');
        $this->postJson("/api/v1/purchase-requisitions/{$r}/approve", [], $h)->assertForbidden();   // a salesperson does not approve
        $this->getJson('/api/v1/purchase-requisitions', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.see_all', false);

        // Another salesperson does not find it.
        $this->actingAs($other, 'sanctum');
        $this->getJson("/api/v1/purchase-requisitions/{$r}", $h)->assertNotFound();
        $this->postJson("/api/v1/purchase-requisitions/{$r}/withdraw", [], $h)->assertNotFound();

        // The approver sees every request; approves this one.
        $this->actingAs($approver, 'sanctum');
        $this->getJson("/api/v1/purchase-requisitions/{$r}", $h)->assertOk()->assertJsonPath('can.decide', true)->assertJsonPath('can.order', false);
        $this->postJson("/api/v1/purchase-requisitions/{$r}/order", ['contact_id' => $c['supplier'], 'account_id' => $expense], $h)->assertForbidden();
        $this->postJson("/api/v1/purchase-requisitions/{$r}/approve", ['note' => 'Fine'], $h)->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('decided_by_name', 'Abebe Approver');
        $this->postJson("/api/v1/purchase-requisitions/{$r}/reject", ['note' => 'Changed my mind'], $h)->assertUnprocessable();   // decided already

        // The buyer orders it: a draft purchase order at the prices given, the estimate otherwise.
        $this->actingAs($buyer, 'sanctum');
        $this->postJson("/api/v1/purchase-requisitions/{$r}/order", ['contact_id' => $c['contact'], 'account_id' => $expense], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('contact_id');   // a customer, not a supplier
        $done = $this->postJson("/api/v1/purchase-requisitions/{$r}/order", ['contact_id' => $c['supplier'], 'account_id' => $expense, 'prices' => [null, '25']], $h)
            ->assertOk()->assertJsonPath('status', 'ordered')->json();
        $po = $this->owner()->table('documents')->where('id', $done['purchase_order_id'])->first();
        $this->assertSame(['purchase_order', 'draft', '1650.00'], [$po->type, $po->status, number_format((float) $po->subtotal, 2, '.', '')]);   // 4 x 350 + 10 x 25
        $this->postJson("/api/v1/purchase-requisitions/{$r}/order", ['contact_id' => $c['supplier'], 'account_id' => $expense], $h)->assertUnprocessable();

        // A rejection says why; the asker withdraws what is still waiting.
        $this->actingAs($sales, 'sanctum');
        $second = $this->postJson('/api/v1/purchase-requisitions', $ask, $h)->assertCreated()->json('id');
        $third = $this->postJson('/api/v1/purchase-requisitions', $ask, $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/purchase-requisitions/{$third}/withdraw", [], $h)->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->actingAs($approver, 'sanctum');
        $this->postJson("/api/v1/purchase-requisitions/{$second}/reject", [], $h)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/purchase-requisitions/{$second}/reject", ['note' => 'We have cups in the store'], $h)->assertOk()->assertJsonPath('status', 'rejected');

        $actions = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $r])->pluck('action')->all();
        foreach (['requisition_requested', 'requisition_approved', 'requisition_ordered'] as $action) {
            $this->assertContains($action, $actions);
        }

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/purchase-requisitions/{$r}", ['X-Company-Id' => $b['company']])->assertNotFound();
        $this->getJson('/api/v1/purchase-requisitions', ['X-Company-Id' => $b['company']])->assertOk()->assertJsonMissing(['id' => $r]);
    }
}
