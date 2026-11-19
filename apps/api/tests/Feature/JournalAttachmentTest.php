<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * Module 6 (086): the board minute behind an accrual is kept with its journal entry, as a receipt is with a bill. Someone
 * who writes journals adds and removes it; someone who reads the books sees and downloads it; a viewer does not add one;
 * another company finds none of it.
 */
final class JournalAttachmentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function test_a_journal_entry_keeps_the_files_behind_it(): void
    {
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company'], 'Accept' => 'application/json'];
        $expense = (string) $this->owner()->table('accounts')->where(['company_id' => $c['company'], 'code' => '6800'])->value('id');
        $entry = $this->postJson('/api/v1/journal-entries', ['date' => '2026-11-10', 'memo' => 'Audit fee accrued', 'lines' => [
            ['account_id' => $expense, 'debit' => '15000'], ['account_id' => $c['equity'], 'credit' => '15000']]], $h)->assertCreated()->json('id');
        $attach = fn (array $headers) => $this->post("/api/v1/journal-entries/{$entry}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('board-minute.pdf', self::PDF)], $headers);

        $this->post("/api/v1/journal-entries/{$entry}/attachments", ['file' => UploadedFile::fake()->createWithContent('run.exe', "MZ\x90\x00binary")], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $id = $attach($h)->assertCreated()->assertJsonPath('content_type', 'application/pdf')->json('id');
        $this->getJson("/api/v1/journal-entries/{$entry}/attachments", $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can.add', true)
            ->assertJsonPath('data.0.file_name', 'board-minute.pdf');
        $this->get("/api/v1/attachments/{$id}", $h)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, $this->owner()->table('audit_log')->where(['company_id' => $c['company'], 'subject_id' => $entry, 'action' => 'attachment_added'])->count());

        // A viewer reads the books: sees and downloads, does not add or remove.
        $viewer = $this->createUser('Vera Viewer', 'vera@example.test');
        $this->owner()->table('memberships')->insert(['id' => (string) Str::uuid(), 'company_id' => $c['company'], 'user_id' => $viewer->getKey(), 'role' => 'viewer']);
        $this->actingAs($viewer, 'sanctum');
        $this->getJson("/api/v1/journal-entries/{$entry}/attachments", $h)->assertOk()->assertJsonPath('can.add', false);
        $this->get("/api/v1/attachments/{$id}", $h)->assertOk();
        $attach($h)->assertForbidden();
        $this->postJson("/api/v1/attachments/{$id}/remove", [], $h)->assertForbidden();

        // Another company finds none of it.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company'], 'Accept' => 'application/json'];
        $this->getJson("/api/v1/journal-entries/{$entry}/attachments", $hb)->assertNotFound();
        $attach($hb)->assertNotFound();
        $this->get("/api/v1/attachments/{$id}", $hb)->assertNotFound();

        // The writer removes it.
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');
        $this->postJson("/api/v1/attachments/{$id}/remove", [], $h)->assertOk();
        $this->getJson("/api/v1/journal-entries/{$entry}/attachments", $h)->assertOk()->assertJsonCount(0, 'data');
    }
}
