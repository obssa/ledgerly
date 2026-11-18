<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 10: the report the reader puts together from the ledger: rows, columns, figure and filters from fixed lists. */
final class CustomReportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_rows_columns_and_filters_add_up_to_the_ledger(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $a = $this->companyWithRecords('a@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $a['company']];
        $period = ['from' => '2026-07-08', 'to' => '2026-12-31'];
        $ledger = fn (string $accountId, string $sql) => (string) $this->owner()->table('journal_lines')
            ->where(['company_id' => $a['company'], 'account_id' => $accountId])->whereBetween('entry_date', [$period['from'], $period['to']])
            ->selectRaw("coalesce(sum({$sql}), 0)::numeric(20,2)::text AS v")->value('v');

        // By account, the whole period, each account on its own side: sales is what the ledger credited it, net.
        $byAccount = $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['rows' => 'account']), $h)->assertOk()->assertJsonPath('columns', ['Total']);
        $sales = collect($byAccount->json('rows'))->firstWhere('key', $a['sales']);
        $this->assertSame($ledger($a['sales'], 'credit - debit'), $sales['total']);
        $this->assertSame($ledger($a['bank'], 'debit - credit'), collect($byAccount->json('rows'))->firstWhere('key', $a['bank'])['total']);

        // Debits only, by month: every month of the period is a column, empty ones too, and the columns add up.
        $monthly = $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['rows' => 'account_type', 'columns' => 'month', 'measure' => 'debit']), $h)->assertOk()
            ->assertJsonPath('columns', ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12']);
        $all = (string) $this->owner()->table('journal_lines')->where('company_id', $a['company'])->whereBetween('entry_date', [$period['from'], $period['to']])
            ->selectRaw('coalesce(sum(debit), 0)::numeric(20,2)::text AS v')->value('v');
        $this->assertSame($all, $monthly->json('total'));
        foreach ($monthly->json('rows') as $row) {
            $this->assertCount(6, $row['values']);
        }

        // Filtered to the project: only its tagged lines; another company's project is refused, not read.
        $tagged = $this->postJson('/api/v1/invoices', ['contact_id' => $a['contact'], 'issue_date' => '2026-11-10', 'currency' => 'ETB',
            'lines' => [['description' => 'Fit-out stage', 'quantity' => '1', 'unit_price' => '700', 'account_id' => $a['sales'], 'project_id' => $a['project']]]], $h)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/{$tagged}/approve", [], $h)->assertOk();
        $project = $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['rows' => 'project', 'project_id' => $a['project']]), $h)->assertOk();
        $this->assertSame([$a['project']], collect($project->json('rows'))->pluck('key')->unique()->values()->all());
        $this->assertSame('700.00', $project->json('total'), 'the tagged income, nothing else');
        $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['project_id' => $b['project']]), $h)->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['rows' => 'a.id; DROP TABLE accounts']), $h)->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['measure' => 'sum(debit)']), $h)->assertUnprocessable()->assertJsonValidationErrors('measure');

        // By customer or supplier, income only: the customer's sales; nothing of company B anywhere.
        $byContact = $this->getJson('/api/v1/reports/custom?' . http_build_query($period + ['rows' => 'contact', 'account_type' => 'income']), $h)->assertOk();
        $this->assertContains('Abyssinia Coffee customer', collect($byContact->json('rows'))->pluck('label')->all());
        $this->assertStringNotContainsString('Blue Nile', (string) $byContact->getContent());

        // Saved with its choices, like any report; a choice outside the lists is refused.
        $this->postJson('/api/v1/saved-reports', ['name' => 'Costs by project', 'report' => 'custom', 'period' => 'this_quarter',
            'options' => ['rows' => 'project', 'columns' => 'month', 'account_type' => 'expense']], $h)->assertCreated()->assertJsonPath('options.rows', 'project');
        $this->postJson('/api/v1/saved-reports', ['name' => 'Odd', 'report' => 'custom', 'period' => 'this_quarter', 'options' => ['rows' => 'password']], $h)
            ->assertUnprocessable();
    }
}
