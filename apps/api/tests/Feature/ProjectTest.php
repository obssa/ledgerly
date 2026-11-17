<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 9a: projects and job costing. The fixture's project P-1 gets an invoice of 2,000.00 (200.00 credited back),
 * a bill of 800.00 and an expense of 115.00 tagged to it: income 1,800.00, costs 915.00, profit 885.00, read from the
 * ledger.
 */
final class ProjectTest extends TestCase
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
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    private function invoice(array $lines): string
    {
        $id = $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'lines' => $lines], $this->h)
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$id}/approve", [], $this->h)->assertOk();

        return $id;
    }

    private function line(string $amount, ?string $project, string $what = 'Fit-out work'): array
    {
        return ['description' => $what, 'quantity' => '1', 'unit_price' => $amount, 'account_id' => $this->c['sales'], 'project_id' => $project];
    }

    public function test_a_projects_profit_is_its_tagged_income_and_costs_from_the_ledger(): void
    {
        $p = $this->c['project'];
        $invoice = $this->invoice([$this->line('2000', $p)]);
        $this->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['issue_date' => '2026-11-13', 'notes' => 'Snag list',
            'lines' => [['description' => 'Allowance', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $this->c['sales'], 'project_id' => $p]]], $this->h)->assertCreated();
        $credit = $this->owner()->table('documents')->where(['company_id' => $this->c['company'], 'type' => 'credit_note', 'original_document_id' => $invoice])->value('id');
        $this->postJson("/api/v1/invoices/{$credit}/approve", [], $this->h)->assertOk();
        $bill = $this->postJson('/api/v1/bills', ['contact_id' => $this->c['supplier'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'reference' => 'TILE-4',
            'lines' => [['description' => 'Tiles', 'quantity' => '1', 'unit_price' => '800', 'account_id' => $this->c['rent'], 'project_id' => $p]]], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/v1/bills/{$bill}/approve", [], $this->h)->assertOk();
        $this->postJson('/api/v1/expenses', ['contact_id' => $this->c['supplier'], 'paid_from_account_id' => $this->c['bank'], 'payment_method' => 'cash',
            'issue_date' => '2026-11-14', 'currency' => 'ETB', 'lines' => [['description' => 'Transport', 'quantity' => '1', 'unit_price' => '115', 'account_id' => $this->c['rent'], 'project_id' => $p]]], $this->h)
            ->assertCreated();

        $shown = $this->getJson("/api/v1/projects/{$p}", $this->h)->assertOk()->assertJsonPath('customer.id', $this->c['contact'])->assertJsonPath('contract_value', '5000.00');
        $this->assertSame(['1800.00', '915.00', '885.00'], [$shown->json('income'), $shown->json('costs'), $shown->json('profit')]);
        $this->assertSame(['915.00', '1800.00'], collect($shown->json('accounts'))->sortBy('type')->pluck('amount')->values()->all());
        $this->assertCount(4, $shown->json('documents'));
        $listed = collect($this->getJson('/api/v1/projects', $this->h)->assertOk()->assertJsonPath('can.manage', true)->json('data'))->firstWhere('id', $p);
        $this->assertSame('885.00', $listed['profit']);

        // The tag is on the income and cost lines in the ledger, not on the receivable or the tax.
        $entry = $this->owner()->table('documents')->where('id', $invoice)->value('journal_entry_id');
        $lines = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->get();
        $this->assertSame($p, $lines->firstWhere('account_id', $this->c['sales'])->project_id);
        $this->assertNull($lines->firstWhere('credit', '0.00')->project_id, 'the receivable is the company\'s, not the project\'s');
        $this->getJson("/api/v1/projects/{$p}?from=2026-11-13&to=2026-11-30", $this->h)->assertOk()->assertJsonPath('income', '-200.00')->assertJsonPath('costs', '115.00');   // the bill of the 12th is before the period
    }

    public function test_lines_of_one_account_post_once_per_project(): void
    {
        $other = $this->postJson('/api/v1/projects', ['code' => 'P-2', 'name' => 'Kiosk', 'status' => 'active', 'billing' => 'time_and_materials'], $this->h)->assertCreated()->json('id');
        $invoice = $this->invoice([$this->line('100', $this->c['project']), $this->line('50', $this->c['project'], 'More'), $this->line('70', $other), $this->line('30', null)]);
        $entry = $this->owner()->table('documents')->where('id', $invoice)->value('journal_entry_id');
        $sales = $this->owner()->table('journal_lines')->where(['journal_entry_id' => $entry, 'account_id' => $this->c['sales']])->get()->keyBy('project_id');
        $this->assertSame(['150.00', '70.00', '30.00'], [(string) $sales[$this->c['project']]->credit, (string) $sales[$other]->credit, (string) $sales['']->credit]);
    }

    public function test_a_finished_project_takes_no_new_lines_and_keeps_its_code(): void
    {
        $p = $this->c['project'];
        $this->putJson("/api/v1/projects/{$p}", ['name' => 'Fit-out', 'status' => 'completed', 'billing' => 'fixed_fee', 'code' => 'P-9'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/projects/{$p}", ['name' => 'Fit-out, phase 1', 'status' => 'completed', 'billing' => 'fixed_fee', 'contract_value' => '5000'], $this->h)->assertOk()
            ->assertJsonPath('is_active', false)->assertJsonPath('status', 'completed')->assertJsonPath('name', 'Fit-out, phase 1');
        $this->postJson('/api/v1/invoices', ['contact_id' => $this->c['contact'], 'issue_date' => '2026-11-12', 'currency' => 'ETB', 'lines' => [$this->line('10', $p)]], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.project_id');
        $this->postJson('/api/v1/projects', ['code' => 'P-1', 'name' => 'Again', 'status' => 'active', 'billing' => 'fixed_fee'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/projects', ['code' => 'P-3', 'name' => 'For a supplier', 'status' => 'active', 'billing' => 'fixed_fee', 'customer_id' => $this->c['supplier']], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertSame(['project_created', 'project_updated'], $this->owner()->table('audit_log')->where('subject_id', $p)->where('action', 'like', 'project_%')->orderBy('id')->pluck('action')->all());
    }
}
