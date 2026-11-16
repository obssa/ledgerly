<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: manual journal entries, and every entry the books hold. */
final class JournalTest extends TestCase
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

    private ?string $accrued = null;

    private function accrual(): string
    {
        return $this->accrued ??= $this->postJson('/api/v1/accounts', ['code' => '2150', 'name' => 'Accrued expenses', 'type' => 'liability', 'kind' => 'accrual'], $this->h)
            ->assertCreated()->json('id');
    }

    private function journal(array $over = [], ?array $lines = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/journal-entries', [...['date' => '2026-11-10', 'memo' => 'November rent accrued', 'lines' => $lines ?? [
            ['account_id' => $this->c['rent'], 'debit' => '500', 'description' => 'Rent, November'],
            ['account_id' => $over['credit_account'] ?? $this->accrual(), 'credit' => '500'],
        ]], ...$over], $this->h);
    }

    public function test_a_manual_journal_is_posted_shown_found_and_reversed_with_a_reason(): void
    {
        $accrued = $this->accrual();
        $entry = $this->journal(['credit_account' => $accrued])->assertCreated()
            ->assertJsonPath('source_type', 'manual')->assertJsonPath('source_label', 'manual journal')->assertJsonPath('total', '500.00')
            ->assertJsonPath('lines.0.code', '6100')->assertJsonPath('lines.0.description', 'Rent, November')->assertJsonPath('lines.1.account', 'Accrued expenses')
            ->assertJsonPath('can.reverse', true);
        $id = $entry->json('id');
        $this->assertNotNull($entry->json('posted_by'));

        $list = $this->getJson('/api/v1/journal-entries?source=manual&search=rent', $this->h)->assertOk()->assertJsonPath('can.create', true);
        $this->assertSame([$id], collect($list->json('data'))->pluck('id')->all());
        $this->assertSame('500.00', $list->json('data.0.total'));
        $this->getJson('/api/v1/journal-entries?search=' . urlencode('100%'), $this->h)->assertOk()->assertJsonCount(0, 'data');

        // Reversed on or after its date, with a reason; then it says so, and the reversal says what it reverses.
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['date' => '2026-11-10'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['date' => '2026-11-09', 'reason' => 'Paid in cash instead'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('date');
        $reversal = $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['date' => '2026-11-12', 'reason' => 'Paid in cash instead'], $this->h)->assertCreated()
            ->assertJsonPath('source_type', 'reversal')->assertJsonPath('reverses.id', $id)->assertJsonPath('can.reverse', false);
        $this->getJson("/api/v1/journal-entries/{$id}", $this->h)->assertOk()->assertJsonPath('status', 'reversed')
            ->assertJsonPath('reversed_by.number', $reversal->json('number'))->assertJsonPath('can.reverse', false);
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['date' => '2026-11-12', 'reason' => 'Again'], $this->h)->assertUnprocessable();
        $this->assertSame('Paid in cash instead', $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'journal_reversed'])->value('reason'));
    }

    public function test_entries_made_by_documents_link_to_them_and_are_corrected_there(): void
    {
        $invoiceEntry = $this->owner()->table('documents')->where('id', $this->c['invoice'])->value('journal_entry_id');
        $this->getJson("/api/v1/journal-entries/{$invoiceEntry}", $this->h)->assertOk()
            ->assertJsonPath('source_label', 'invoice')->assertJsonPath('document.id', $this->c['invoice'])->assertJsonPath('can.reverse', false);
        $this->postJson("/api/v1/journal-entries/{$invoiceEntry}/reverse", ['date' => '2026-11-12', 'reason' => 'Wrong'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $paymentEntry = $this->owner()->table('payments')->where('id', $this->c['payment'])->value('journal_entry_id');
        $this->getJson("/api/v1/journal-entries/{$paymentEntry}", $this->h)->assertOk()->assertJsonPath('payment.id', $this->c['payment']);
    }

    public function test_what_a_manual_journal_may_not_do(): void
    {
        $receivable = $this->owner()->table('accounts')->where(['company_id' => $this->c['company'], 'system_key' => 'accounts_receivable'])->value('id');
        $accrued = $this->accrual();
        $this->journal(['credit_account' => $receivable])->assertUnprocessable()->assertJsonValidationErrors('lines.1.account_id');
        $this->journal(['memo' => ''])->assertUnprocessable()->assertJsonValidationErrors('memo');
        $this->journal(['date' => '2099-01-01', 'credit_account' => $accrued])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->journal([], [['account_id' => $this->c['rent'], 'debit' => '500'], ['account_id' => $accrued, 'credit' => '499.99']])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->journal([], [['account_id' => $this->c['rent'], 'debit' => '5', 'credit' => '5'], ['account_id' => $accrued, 'credit' => '0']])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit', 'lines.1.debit']);
        $usd = $this->postJson('/api/v1/bank-accounts', ['name' => 'USD', 'kind' => 'bank', 'currency' => 'USD'], $this->h)->assertCreated()->json('id');
        $this->journal([], [['account_id' => $usd, 'debit' => '5'], ['account_id' => $accrued, 'credit' => '5']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->assertSame(0, $this->owner()->table('journal_entries')->where(['company_id' => $this->c['company'], 'memo' => 'November rent accrued'])->count());
    }

    public function test_bookkeepers_read_the_journal_and_do_not_post_to_it(): void
    {
        $user = $this->createUser('Bookkeeper', 'bk@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $user->getKey(), 'role' => 'bookkeeper']);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/journal-entries', $this->h)->assertOk()->assertJsonPath('can.create', false);
        $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-10', 'memo' => 'x', 'lines' => []], $this->h)->assertForbidden();
    }
}
