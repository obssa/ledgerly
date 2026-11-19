<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 3: employee expense claims (083). A salesperson paid 450.00 for a taxi and 230.00 for lunch with a customer:
 * they claim 680.00, see only their own claims and submit; the claim is a bill to their own staff payee (made the first
 * time). They cannot approve it; the owner does, and it is owed to them like any bill. A viewer cannot claim, another
 * member does not find their claim, and another company finds none of it.
 */
final class ExpenseClaimTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_member_claims_back_what_they_paid_and_someone_else_approves_it(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $owner = User::query()->findOrFail($c['user']);
        $member = function (string $role, string $name) use ($c) {
            $user = $this->createUser($name, strtolower(str_replace(' ', '.', $name)) . '@example.test');
            $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $user->getKey(), 'role' => $role]);

            return $user;
        };
        // Another salesperson sees no bills, so only their own claims (purchasing staff, who see bills, see every claim).
        [$sales, $other, $viewer] = [$member('sales', 'Selam Tesfaye'), $member('sales', 'Dawit Bekele'), $member('viewer', 'Vera Viewer')];
        $expense = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '6800'])->value('id');
        $claim = fn (array $over = []) => ['issue_date' => '2026-11-12', 'notes' => 'Visit to Hawassa', 'lines' => [
            ['description' => 'Taxi to the airport', 'amount' => '450', 'account_id' => $expense],
            ['description' => 'Lunch with Sidama Coffee', 'amount' => '230', 'account_id' => $expense],
        ], ...$over];

        $this->actingAs($viewer, 'sanctum');
        $this->postJson('/api/v1/expense-claims', $claim(), $h)->assertForbidden();

        $this->actingAs($sales, 'sanctum');
        $this->postJson('/api/v1/expense-claims', $claim(['lines' => [['description' => 'Rent', 'amount' => '10', 'account_id' => $c['sales']]]]), $h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');   // an expense account
        $made = $this->postJson('/api/v1/expense-claims', $claim(), $h)->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('total', '680.00')
            ->assertJsonPath('claimed_by', $sales->getKey())->assertJsonPath('can.submit', true)->json();
        $payee = $this->owner()->table('contacts')->where(['company_id' => $c['company'], 'employee_user_id' => $sales->getKey()])->first();
        $this->assertSame([$payee->id, true], [$made['contact_id'], (bool) $payee->is_vendor]);
        $second = $this->postJson('/api/v1/expense-claims', $claim(['lines' => [['description' => 'Parking', 'amount' => '40', 'account_id' => $expense]]]), $h)->assertCreated()->json();
        $this->assertSame($payee->id, $second['contact_id'], 'one staff payee per member');
        $this->getJson('/api/v1/expense-claims', $h)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('can.see_all', false);
        $this->postJson("/api/v1/expense-claims/{$made['id']}/submit", [], $h)->assertOk()->assertJsonPath('status', 'awaiting_approval');

        // Another member does not find it; the claimant does not approve it.
        $this->actingAs($other, 'sanctum');
        $this->getJson("/api/v1/expense-claims/{$made['id']}", $h)->assertNotFound();
        $this->postJson("/api/v1/expense-claims/{$made['id']}/submit", [], $h)->assertNotFound();
        $this->getJson("/api/v1/expense-claims/{$made['id']}/attachments", $h)->assertNotFound();
        $this->getJson('/api/v1/expense-claims', $h)->assertOk()->assertJsonCount(0, 'data');

        // The owner, who sees bills, sees every claim and approves it: owed to the salesperson like any bill.
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/v1/expense-claims', $h)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('can.see_all', true);   // with the fixture's own draft
        $this->getJson("/api/v1/expense-claims/{$made['id']}", $h)->assertOk()->assertJsonPath('can.approve', true);
        $this->postJson("/api/v1/bills/{$made['id']}/approve", [], $h)->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('amount_due', '680.00');
        $this->getJson("/api/v1/payments-made/withholding/{$payee->id}", $h)->assertOk();
        $this->assertContains('expense_claimed', $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $made['id']])->pluck('action')->all());

        // A claimant who could approve bills still does not post their own.
        $own = $this->postJson('/api/v1/expense-claims', $claim(), $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$own}/approve", [], $h)->assertUnprocessable();

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->getJson("/api/v1/expense-claims/{$made['id']}", ['X-Company-Id' => $b['company']])->assertNotFound();
        $this->getJson('/api/v1/expense-claims', ['X-Company-Id' => $b['company']])->assertOk()->assertJsonMissing(['id' => $made['id']]);
    }
}
