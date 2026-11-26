<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 10: every exported report is recorded in the audit log, with who exported it, when, and what. */
final class ReportExportTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_export_is_recorded_with_its_report_and_period(): void
    {
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $this->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'csv', 'from' => '2026-07-08', 'to' => '2026-11-17',
            'options' => ['group' => 'item'], 'rows' => 12], $h)->assertCreated()->assertJsonPath('recorded', true);
        $log = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'report_exported'])->sole();
        $this->assertSame($this->lastUser->getKey(), $log->user_id);
        $this->assertEquals(['report' => 'sales', 'format' => 'csv', 'from' => '2026-07-08', 'to' => '2026-11-17', 'options' => ['group' => 'item'], 'rows' => 12],
            json_decode((string) $log->after, true));

        $this->postJson('/api/v1/reports/exports', ['report' => 'payroll', 'format' => 'csv', 'rows' => 1], $h)->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'docx', 'rows' => 1], $h)->assertUnprocessable()->assertJsonValidationErrors('format');
        // An Excel download, and a report printed or saved as PDF from the browser: recorded the same way, with its format.
        $this->postJson('/api/v1/reports/exports', ['report' => 'balance-sheet', 'format' => 'xlsx', 'as_of' => '2026-11-17', 'rows' => 30], $h)->assertCreated();
        $this->postJson('/api/v1/reports/exports', ['report' => 'balance-sheet', 'format' => 'pdf', 'as_of' => '2026-11-17', 'rows' => 30], $h)->assertCreated();
        $this->assertSame('pdf', json_decode((string) $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'report_exported'])
            ->orderByDesc('id')->value('after'), true)['format']);
        $this->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'csv', 'rows' => 1, 'options' => ['sql' => 'x']], $h)->assertUnprocessable();
        // A report's Customize choices are recorded with it: the columns in order, the sort, the ageing's periods.
        $this->postJson('/api/v1/reports/exports', ['report' => 'open-invoices', 'format' => 'csv', 'rows' => 3,
            'options' => ['cols' => 'date,number,name,due_date,amount,open_balance,foreign_amount,foreign_open_balance,currency', 'sort' => 'date', 'dir' => 'asc', 'group' => 'name']], $h)->assertCreated();
        $this->postJson('/api/v1/reports/exports', ['report' => 'ar-ageing', 'format' => 'xlsx', 'as_of' => '2026-11-17', 'rows' => 5,
            'options' => ['view' => 'detail', 'interval' => '15', 'periods' => '6', 'group' => 'age']], $h)->assertCreated();

        // A role without reports cannot record one either.
        $sales = $this->createUser('Sales', 'sales@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $sales->getKey(), 'role' => 'sales']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($sales, 'sanctum')->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'csv', 'rows' => 1], $h)->assertForbidden();
    }
}
