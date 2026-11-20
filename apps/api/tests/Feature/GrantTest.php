<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9a (093): grants for NGOs. A clean-water project is set up as a grant from the customer as donor: 100,000.00
 * awarded, restricted, a 30,000.00 budget on rent. A 12,000.00 bill on rent posts; a 500.00 bill on field travel is
 * refused while it is restricted. The donor's 40,000.00 invoice is its income. The donor report shows the budget line
 * (12,000.00 spent, 18,000.00 left), the fund balance (28,000.00) and the award still to come (60,000.00); unrestricted,
 * the travel bill posts and shows as spending outside the budget. Another company finds none of it.
 */
final class GrantTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_a_grant_is_spent_on_its_budget_lines_and_reported_to_its_donor(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $p = $this->postJson('/api/v1/projects', ['code' => 'GR-1', 'name' => 'Clean water grant', 'status' => 'active', 'billing' => 'non_billable', 'starts_on' => '2026-11-01'], $h)
            ->assertCreated()->json('id');
        $travel = $this->postJson('/api/v1/accounts', ['code' => '6120', 'name' => 'Field travel', 'type' => 'expense'], $h)->assertCreated()->json('id');

        $grant = fn (array $body) => $this->putJson("/api/v1/projects/{$p}/grant", [...['donor_id' => $c['contact'], 'award' => '100000', 'reference' => 'WASH-01',
            'restricted' => true, 'lines' => [['account_id' => $c['rent'], 'amount' => '30000']]], ...$body], $h);
        $grant(['lines' => [['account_id' => $c['sales'], 'amount' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $grant(['lines' => [['account_id' => $c['rent'], 'amount' => '1'], ['account_id' => $c['rent'], 'amount' => '2']]])->assertUnprocessable()->assertJsonValidationErrors('lines.1.account_id');
        $grant(['donor_id' => $b['contact']])->assertUnprocessable()->assertJsonValidationErrors('donor_id');
        $grant([])->assertOk()->assertJsonPath('grant.award', '100000.00')->assertJsonPath('grant.budget', '30000.00')->assertJsonPath('grant.restricted', true)
            ->assertJsonPath('grant.donor.id', $c['contact'])->assertJsonPath('grant.reference', 'WASH-01');

        $bill = fn (string $account, string $amount, string $ref) => $this->postJson('/api/v1/bills', ['contact_id' => $c['supplier'], 'issue_date' => '2026-11-15', 'currency' => 'ETB',
            'reference' => $ref, 'lines' => [['description' => 'Grant spending', 'quantity' => '1', 'unit_price' => $amount, 'account_id' => $account, 'project_id' => $p]]], $h)
            ->assertCreated()->json('id');
        $this->postJson('/api/v1/bills/' . $bill($c['rent'], '12000', 'G-RENT') . '/approve', [], $h)->assertOk();
        $offBudget = $bill($travel, '500', 'G-TRAVEL');
        $this->assertStringContainsString('restricted', (string) $this->postJson("/api/v1/bills/{$offBudget}/approve", [], $h)->assertUnprocessable()->json('message'));
        $invoice = $this->postJson('/api/v1/invoices', ['contact_id' => $c['contact'], 'issue_date' => '2026-11-16', 'currency' => 'ETB',
            'lines' => [['description' => 'First tranche', 'quantity' => '1', 'unit_price' => '40000', 'account_id' => $c['sales'], 'project_id' => $p]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$invoice}/approve", [], $h)->assertOk();

        $report = fn () => $this->getJson("/api/v1/projects/{$p}/donor-report?from=2026-11-01&to=2026-11-30", $h)->assertOk()->json();
        $r = $report();
        $this->assertSame(['30000.00', '12000.00', '12000.00', '18000.00', false],
            [$r['lines'][0]['budget'], $r['lines'][0]['period'], $r['lines'][0]['to_date'], $r['lines'][0]['remaining'], $r['lines'][0]['over']]);
        $this->assertSame([], $r['outside_budget']);
        $this->assertSame(['40000.00', '28000.00', '60000.00'], [$r['income']['to_date'], $r['fund_balance'], $r['award_to_come']]);

        // Unrestricted, the travel bill posts, and the report shows it outside the budget.
        $grant(['restricted' => false])->assertOk()->assertJsonPath('grant.restricted', false);
        $this->postJson("/api/v1/bills/{$offBudget}/approve", [], $h)->assertOk();
        $r = $report();
        $this->assertSame([$travel, '500.00'], [$r['outside_budget'][0]['account_id'], $r['outside_budget'][0]['to_date']]);
        $this->assertSame(['12500.00', '27500.00'], [$r['spent']['to_date'], $r['fund_balance']]);
        $this->assertSame(2, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $p, 'action' => 'grant_set'])->count());

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company']];
        $this->putJson("/api/v1/projects/{$p}/grant", ['award' => '1', 'restricted' => false, 'lines' => []], $hb)->assertNotFound();
        $this->getJson("/api/v1/projects/{$p}/donor-report", $hb)->assertNotFound();
    }
}
