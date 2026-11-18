<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 9a: materials issued from a warehouse to a project (055) leave stock at cost and become the project's cost. */
final class StockIssueTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_stock_issued_to_a_project_leaves_at_cost_and_counts_in_the_projects_profit(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // 20 bags at 50.00 in the main store
        $h = ['X-Company-Id' => $c['company']];
        $issue = fn (array $over = []) => $this->postJson('/api/v1/stock/issues', [...['item_id' => $c['stock_item'], 'warehouse_id' => $c['store'],
            'project_id' => $c['project'], 'account_id' => $c['rent'], 'date' => '2026-11-10', 'quantity' => '4'], ...$over], $h);
        $costsBefore = $this->getJson("/api/v1/projects/{$c['project']}", $h)->assertOk()->json('costs');

        $issue(['quantity' => '100'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $issue(['account_id' => $c['sales']])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $issue(['project_id' => $b['project']])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $issue(['warehouse_id' => $b['store']])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        $done = $issue()->assertCreated()->assertJsonPath('amount', '200.00');
        $lines = $this->owner()->table('journal_lines')->where(['company_id' => $c['company'], 'journal_entry_id' => $done->json('journal_entry_id')])->get();
        $cost = $lines->firstWhere('account_id', $c['rent']);
        $this->assertSame(['200.00', $c['project']], [(string) $cost->debit, $cost->project_id]);
        $inventory = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'system_key' => 'inventory'])->value('id');
        $this->assertSame('200.00', (string) $lines->firstWhere('account_id', $inventory)->credit);

        $this->assertSame('16.00', number_format((float) $this->owner()->table('stock_balances')->where(['company_id' => $c['company'], 'item_id' => $c['stock_item'],
            'warehouse_id' => $c['store']])->value('quantity'), 2, '.', ''));
        $this->assertSame(bcadd((string) $costsBefore, '200', 2), $this->getJson("/api/v1/projects/{$c['project']}", $h)->assertOk()->json('costs'));
        $move = $this->owner()->table('stock_moves')->where(['company_id' => $c['company'], 'id' => $done->json('move_id')])->first();
        $this->assertSame(['project_issue', $c['project']], [$move->kind, $move->project_id]);
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'stock_issued_to_project'])->count());
    }
}
