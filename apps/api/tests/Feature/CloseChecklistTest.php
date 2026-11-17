<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6: the close checklist. The fixtures leave work open on 10 Nov: the bank is not reconciled, two statement
 * lines wait, drafts and a journal request are dated before then. Stock agrees with the inventory account.
 */
final class CloseChecklistTest extends TestCase
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

    public function test_the_checklist_says_what_is_left_and_the_close_keeps_what_it_said(): void
    {
        $list = $this->getJson('/api/v1/close-checklist?period_end=2026-11-10', $this->h)->assertOk()->assertJsonPath('period_end', '2026-11-10');
        $items = collect($list->json('items'))->keyBy('key');
        $this->assertSame(['banks_reconciled', 'statement_lines', 'stock_valued', 'open_documents', 'journal_requests', 'depreciation', 'undeposited', 'balanced'], $items->keys()->all());
        $this->assertSame('warn', $items['banks_reconciled']['status']);
        $this->assertContains("/banking/{$this->c['bank']}/reconcile", array_column($items['banks_reconciled']['items'], 'link'));
        $this->assertSame(['warn', 'statement line'], [$items['statement_lines']['status'], substr($items['statement_lines']['detail'], 2, 14)]);
        $this->assertSame('ok', $items['stock_valued']['status'], $items['stock_valued']['detail']);
        $this->assertSame('warn', $items['open_documents']['status']);
        $this->assertSame('warn', $items['journal_requests']['status']);
        $this->assertSame('ok', $items['balanced']['status']);
        $this->assertSame($items->where('status', '!=', 'ok')->count(), $list->json('warnings'));

        // Closing is not blocked; the record keeps which items were open.
        $this->putJson('/api/v1/company/period-lock', ['books_closed_until' => '2026-11-10'], $this->h)->assertOk();
        $after = json_decode((string) $this->owner()->table('audit_log')->where(['subject_id' => $this->c['company'], 'action' => 'period_closed'])->value('after'), true);
        $this->assertContains('banks_reconciled', $after['checklist_warnings']);
        $this->assertNotContains('balanced', $after['checklist_warnings']);
        $this->getJson('/api/v1/close-checklist?period_end=2026-11-10', $this->h)->assertOk()->assertJsonPath('books_closed_until', '2026-11-10');
    }

    public function test_the_period_end_defaults_to_last_month_and_is_not_in_the_future(): void
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Addis_Ababa'));   // the company's time zone (ET pack)
        $this->getJson('/api/v1/close-checklist', $this->h)->assertOk()
            ->assertJsonPath('period_end', $today->modify('first day of this month')->modify('-1 day')->format('Y-m-d'));
        $this->getJson('/api/v1/close-checklist?period_end=2099-01-31', $this->h)->assertUnprocessable()->assertJsonValidationErrors('period_end');
    }

    public function test_only_those_who_close_periods_see_the_checklist(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'viewer']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/close-checklist', $this->h)->assertForbidden();
    }
}
