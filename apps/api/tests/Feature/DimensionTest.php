<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 1, locations and dimensions: added, renamed and turned off (never deleted) with an audit record; journal
 * lines carry only active dimensions of the right kind; old entries with a turned-off dimension still reverse.
 * Roles: PermissionMatrixTest. Other companies: TenantIsolationTest (header, URL, body) and T41.
 */
final class DimensionTest extends TestCase
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
        $this->c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');   // has location HQ
        $this->h = ['X-Company-Id' => $this->c['company']];
    }

    public function test_dimensions_are_added_renamed_and_turned_off_with_an_audit_record(): void
    {
        $id = $this->postJson('/api/v1/dimensions', ['kind' => 'project', 'code' => 'P-001', 'name' => 'Hotel fit-out'], $this->h)
            ->assertCreated()->assertJsonPath('is_active', true)->json('id');
        $this->postJson('/api/v1/dimensions', ['kind' => 'project', 'code' => 'P-001', 'name' => 'Again'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/dimensions', ['kind' => 'location', 'code' => 'P-001', 'name' => 'Same code, other kind'], $this->h)->assertCreated();
        $this->postJson('/api/v1/dimensions', ['kind' => 'region', 'code' => 'X', 'name' => 'Unknown kind'], $this->h)
            ->assertUnprocessable()->assertJsonValidationErrors('kind');

        $this->putJson("/api/v1/dimensions/{$id}", ['name' => 'Sheger hotel fit-out', 'is_active' => false, 'code' => 'P-999'], $this->h)
            ->assertOk()->assertJsonPath('name', 'Sheger hotel fit-out')->assertJsonPath('is_active', false)->assertJsonPath('code', 'P-001');

        $this->getJson('/api/v1/dimensions?kind=project', $this->h)->assertOk()->assertJsonCount(1, 'data');
        $audit = $this->owner()->table('audit_log')->where(['company_id' => $this->c['company'], 'subject_type' => 'dimension', 'action' => 'updated'])->sole();
        $this->assertEquals(['name' => 'Hotel fit-out', 'is_active' => true], json_decode($audit->before, true));
    }

    public function test_a_journal_line_carries_active_dimensions_of_the_right_kind(): void
    {
        $retail = $this->postJson('/api/v1/dimensions', ['kind' => 'class', 'code' => 'RETAIL', 'name' => 'Retail'], $this->h)->json('id');

        $entry = $this->entry(['location_id' => $this->c['dimension'], 'class_id' => $retail])->assertCreated()->json('id');
        $line = $this->owner()->table('journal_lines')->where('journal_entry_id', $entry)->where('debit', '>', 0)->sole();
        $this->assertSame([$this->c['dimension'], $retail], [$line->location_id, $line->class_id]);

        $this->entry(['location_id' => $retail])->assertUnprocessable()->assertJsonValidationErrors('lines.0.location_id');
        $this->entry(['project_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('lines.0.project_id');

        // Turned off: no new entries, but the old one still reverses with its dimensions.
        $this->putJson("/api/v1/dimensions/{$this->c['dimension']}", ['is_active' => false], $this->h)->assertOk();
        $this->entry(['location_id' => $this->c['dimension']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.location_id');
        $this->postJson("/api/v1/journal-entries/{$entry}/reverse", ['date' => '2026-11-06', 'reason' => 'Posted to the wrong location'], $this->h)->assertCreated();
        // The tagged line and its mirror in the reversal.
        $this->assertSame(2, $this->owner()->table('journal_lines')->where('location_id', $this->c['dimension'])->count());
    }

    /** @param array<string, string> $dimensions on the debit line */
    private function entry(array $dimensions): TestResponse
    {
        return $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-05', 'memo' => 'Petty cash', 'lines' => [
            ['account_id' => $this->c['bank'], 'debit' => '100', ...$dimensions],
            ['account_id' => $this->c['equity'], 'credit' => '100'],
        ]], $this->h);
    }
}
