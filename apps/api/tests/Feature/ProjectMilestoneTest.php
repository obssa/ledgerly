<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 9a: milestones and progress billing on a fixed-fee project (049). */
final class ProjectMilestoneTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // project P-1, contract 5,000, one milestone
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    public function test_a_milestone_is_billed_once_on_a_draft_invoice_to_the_customer_and_kept_as_billed(): void
    {
        $p = $this->c['project'];
        // Progress billing: a share of the contract value, rounded half up to the cent.
        $list = $this->postJson("/api/v1/projects/{$p}/milestones", ['name' => 'Design', 'percent' => '30', 'due_on' => '2026-12-15'], $this->h)->assertCreated()->json('data');
        $design = collect($list)->firstWhere('name', 'Design');
        $this->assertSame('1500.00', $design['amount']);
        $this->assertSame('planned', $design['status']);
        $this->postJson("/api/v1/projects/{$p}/milestones", ['name' => 'Nothing'], $this->h)->assertUnprocessable()->assertJsonValidationErrors(['amount', 'percent']);
        // Rounded half up to the cent: 33.33% of 5,000 is 1,666.50; 0.01% is 0.50.
        $odd = collect($this->postJson("/api/v1/projects/{$p}/milestones", ['name' => 'A third', 'percent' => '33.33'], $this->h)->json('data'))->firstWhere('name', 'A third');
        $this->assertSame('1666.50', $odd['amount']);
        $this->deleteJson("/api/v1/projects/{$p}/milestones/{$odd['id']}", [], $this->h)->assertOk();

        $billed = $this->postJson("/api/v1/projects/{$p}/milestones/{$design['id']}/bill", ['issue_date' => '2026-11-10', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertCreated()->assertJsonPath('invoice.total', '1500.00');
        $invoice = $billed->json('invoice.id');
        $this->assertSame('drafted', collect($billed->json('data'))->firstWhere('id', $design['id'])['status']);
        $draft = $this->getJson("/api/v1/invoices/{$invoice}", $this->h)->assertOk()->assertJsonPath('contact_id', $this->c['contact'])->assertJsonPath('status', 'draft');
        $this->assertSame($p, $draft->json('lines.0.project_id'));

        // Billed once; the database keeps it as billed.
        $this->postJson("/api/v1/projects/{$p}/milestones/{$design['id']}/bill", ['issue_date' => '2026-11-10', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('milestone');
        $this->putJson("/api/v1/projects/{$p}/milestones/{$design['id']}", ['name' => 'Design', 'amount' => '1'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('milestone');
        $this->deleteJson("/api/v1/projects/{$p}/milestones/{$design['id']}", [], $this->h)->assertUnprocessable();

        // Voiding the draft opens the milestone again: it can change, and be billed again.
        $this->postJson("/api/v1/invoices/{$invoice}/void", ['reason' => 'Wrong stage'], $this->h)->assertOk();
        $this->assertSame('planned', collect($this->getJson("/api/v1/projects/{$p}", $this->h)->json('milestones'))->firstWhere('id', $design['id'])['status']);
        $this->putJson("/api/v1/projects/{$p}/milestones/{$design['id']}", ['name' => 'Design, revised', 'amount' => '1600'], $this->h)->assertOk();
        $again = $this->postJson("/api/v1/projects/{$p}/milestones/{$design['id']}/bill", ['issue_date' => '2026-11-11', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertCreated()->assertJsonPath('invoice.total', '1600.00')->json('invoice.id');

        // Posted, it shows as invoiced (a posted invoice is corrected by a credit note, so the milestone stays billed).
        $this->postJson("/api/v1/invoices/{$again}/approve", [], $this->h)->assertOk();
        $this->assertSame('invoiced', collect($this->getJson("/api/v1/projects/{$p}", $this->h)->json('milestones'))->firstWhere('id', $design['id'])['status']);
        $this->deleteJson("/api/v1/projects/{$p}/milestones/{$design['id']}", [], $this->h)->assertUnprocessable();

        // A planned one goes.
        $this->deleteJson("/api/v1/projects/{$p}/milestones/{$this->c['milestone']}", [], $this->h)->assertOk();

        $this->assertSame(['milestone_added', 'milestone_billed', 'milestone_changed', 'milestone_removed'],
            $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'subject_id' => $p])->where('action', 'like', 'milestone_%')
                ->pluck('action')->unique()->sort()->values()->all());
    }

    public function test_a_project_without_a_customer_or_contract_value_is_told_what_is_missing(): void
    {
        $p = $this->postJson('/api/v1/projects', ['code' => 'P-2', 'name' => 'In-house', 'status' => 'active', 'billing' => 'fixed_fee'], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/projects/{$p}/milestones", ['name' => 'Half', 'percent' => '50'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('percent');
        $m = $this->postJson("/api/v1/projects/{$p}/milestones", ['name' => 'Fixed', 'amount' => '800'], $this->h)->assertCreated()->json('data.0.id');
        $this->postJson("/api/v1/projects/{$p}/milestones/{$m}/bill", ['issue_date' => '2026-11-10', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('project');
        // Another project's milestone is not found under this one.
        $this->postJson("/api/v1/projects/{$p}/milestones/{$this->c['milestone']}/bill", ['issue_date' => '2026-11-10', 'income_account_id' => $this->c['sales']], $this->h)
            ->assertNotFound();
    }
}
