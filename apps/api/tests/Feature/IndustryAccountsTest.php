<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1: industry templates after setup. A company set up without one picks "Professional and other services":
 * each template account is shown as one the chart has, one whose code another account uses, or one to add. Adding
 * puts in only the ones to add (never over an account on that code), once; a second go adds nothing. The audit log
 * keeps what was added; another company's chart is untouched.
 */
final class IndustryAccountsTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    public function test_an_industry_template_adds_the_accounts_the_chart_lacks(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company']];
        $theirs = $this->owner()->table('accounts')->where('company_id', $b['company'])->count();

        // Another account already sits on 6910, the template's "Subcontractors".
        $this->postJson('/api/v1/accounts', ['code' => '6910', 'name' => 'Outsourced roasting', 'type' => 'expense'], $h)->assertCreated();
        $services = collect($this->getJson('/api/v1/chart/industries', $h)->assertOk()->assertJsonPath('industry', null)->assertJsonPath('can.manage', true)
            ->json('data'))->firstWhere('key', 'services');
        $status = collect($services['accounts'])->pluck('status', 'code');
        $this->assertSame('taken', $status['6910']);
        $toAdd = $status->filter(fn ($s) => $s === 'add')->keys()->map(fn ($k) => (string) $k)->sort()->values()->all();
        $this->assertNotEmpty($toAdd);
        $this->assertSame(count($toAdd), $services['to_add']);

        $this->postJson('/api/v1/chart/industry-accounts', ['industry' => 'florist'], $h)->assertUnprocessable()->assertJsonValidationErrors('industry');
        $added = $this->postJson('/api/v1/chart/industry-accounts', ['industry' => 'services'], $h)->assertCreated()->json('added');
        $this->assertSame($toAdd, collect($added)->pluck('code')->sort()->values()->all());
        $chart = collect($this->getJson('/api/v1/chart', $h)->assertOk()->json('data'));
        $this->assertSame('Outsourced roasting', $chart->firstWhere('code', '6910')['name']);
        foreach ($toAdd as $code) {
            $this->assertNotNull($chart->firstWhere('code', $code));
        }
        $this->postJson('/api/v1/chart/industry-accounts', ['industry' => 'services'], $h)->assertOk()->assertJsonPath('added', []);
        $after = collect(collect($this->getJson('/api/v1/chart/industries', $h)->json('data'))->firstWhere('key', 'services')['accounts'])->pluck('status', 'code');
        $this->assertNotContains('add', $after->all());

        $audit = $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'action' => 'industry_accounts_added'])->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertCount(count($toAdd), json_decode((string) $audit[0]->after, true)['accounts']);

        // Another company's chart is untouched, and its own call adds to its own chart only.
        $this->assertSame($theirs, $this->owner()->table('accounts')->where('company_id', $b['company'])->count());
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $this->postJson('/api/v1/chart/industry-accounts', ['industry' => 'services'], ['X-Company-Id' => $b['company']])->assertCreated();
        $this->assertSame('Outsourced roasting', $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '6910'])->value('name'));
    }
}
