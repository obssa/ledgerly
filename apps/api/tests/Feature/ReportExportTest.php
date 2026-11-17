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
        $this->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'xlsx', 'rows' => 1], $h)->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'csv', 'rows' => 1, 'options' => ['sql' => 'x']], $h)->assertUnprocessable();

        // A role without reports cannot record one either.
        $sales = $this->createUser('Sales', 'sales@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $sales->getKey(), 'role' => 'sales']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($sales, 'sanctum')->postJson('/api/v1/reports/exports', ['report' => 'sales', 'format' => 'csv', 'rows' => 1], $h)->assertForbidden();
    }
}
