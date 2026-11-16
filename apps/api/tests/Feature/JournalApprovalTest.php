<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** Module 6: manual journals above the company's approval limit wait for someone other than the person who asked. */
final class JournalApprovalTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    /** @var array<string, string> */
    private array $c;

    private array $h;

    private User $ownerUser;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $this->ownerUser = $this->lastUser;
        $this->h = ['X-Company-Id' => $this->c['company']];
        $this->accountant = $this->createUser('Accountant', 'acc@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $this->c['company'], 'user_id' => $this->accountant->getKey(), 'role' => 'accountant']);

        $version = $this->getJson('/api/v1/company', $this->h)->assertOk()->json('version');
        $this->putJson('/api/v1/company', ['version' => $version, 'approval' => ['journal_above' => '1000']], $this->h)->assertOk()
            ->assertJsonPath('approval.journal_above', '1000');
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    private function journal(string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-10', 'memo' => "Provision of {$amount}", 'lines' => [
            ['account_id' => $this->c['rent'], 'debit' => $amount], ['account_id' => $this->c['equity'], 'credit' => $amount],
        ]], $this->h);
    }

    public function test_a_journal_above_the_limit_waits_and_is_posted_by_someone_else(): void
    {
        $this->as($this->ownerUser)->journal('500')->assertCreated();   // under the limit: posted at once
        $asked = $this->journal('1500')->assertStatus(202)->assertJsonPath('request.status', 'pending')->assertJsonPath('request.total', '1500.00')
            ->assertJsonPath('request.can.approve', false)->assertJsonPath('request.can.withdraw', true);
        $id = $asked->json('request.id');
        $this->assertSame(0, $this->owner()->table('journal_entries')->where(['company_id' => $this->c['company'], 'memo' => 'Provision of 1500'])->count());
        $this->postJson("/api/v1/journal-requests/{$id}/approve", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->as($this->accountant)->getJson('/api/v1/journal-requests', $this->h)->assertOk()->assertJsonPath('pending', 2)   // with the fixture's
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.can.approve', true);
        $entry = $this->postJson("/api/v1/journal-requests/{$id}/approve", [], $this->h)->assertCreated()->assertJsonPath('source_type', 'manual')
            ->assertJsonPath('total', '1500.00')->assertJsonPath('posted_by', 'Accountant');
        $this->getJson("/api/v1/journal-requests/{$id}", $this->h)->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('journal_entry.number', $entry->json('number'))->assertJsonPath('decided_by', 'Accountant');
        $this->postJson("/api/v1/journal-requests/{$id}/approve", [], $this->h)->assertUnprocessable();
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['subject_id' => $id, 'action' => 'journal_approved'])->count());
    }

    public function test_a_request_is_rejected_with_a_note_or_withdrawn_by_the_person_who_asked(): void
    {
        $first = $this->as($this->ownerUser)->journal('2000')->assertStatus(202)->json('request.id');
        $second = $this->journal('3000')->assertStatus(202)->json('request.id');

        $this->as($this->accountant)->postJson("/api/v1/journal-requests/{$first}/reject", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/journal-requests/{$first}/reject", ['note' => 'Provide in December, not November'], $this->h)->assertOk()
            ->assertJsonPath('status', 'rejected')->assertJsonPath('note', 'Provide in December, not November');
        $this->postJson("/api/v1/journal-requests/{$second}/withdraw", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->as($this->ownerUser)->postJson("/api/v1/journal-requests/{$second}/reject", ['note' => 'x x x'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/journal-requests/{$second}/withdraw", [], $this->h)->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->getJson('/api/v1/journal-requests?status=all', $this->h)->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(0, $this->owner()->table('journal_entries')->where('company_id', $this->c['company'])->where('memo', 'like', 'Provision of %000')->count());
    }

    public function test_an_approval_checks_the_journal_again(): void
    {
        $id = $this->as($this->ownerUser)->journal('1500')->assertStatus(202)->json('request.id');
        // The account was archived after the request was made: the approval is refused and nothing posts.
        $spare = $this->postJson('/api/v1/accounts', ['code' => '6199', 'name' => 'Spare', 'type' => 'expense'], $this->h)->assertCreated()->json('id');
        $other = $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-10', 'memo' => 'Uses spare', 'lines' => [
            ['account_id' => $spare, 'debit' => '1001'], ['account_id' => $this->c['equity'], 'credit' => '1001']]], $this->h)->assertStatus(202)->json('request.id');
        $this->putJson("/api/v1/accounts/{$spare}", ['is_active' => false], $this->h)->assertOk();
        $this->as($this->accountant)->postJson("/api/v1/journal-requests/{$other}/approve", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->getJson("/api/v1/journal-requests/{$other}", $this->h)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson("/api/v1/journal-requests/{$id}/approve", [], $this->h)->assertCreated();
    }
}
