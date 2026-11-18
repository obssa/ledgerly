<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\CompanyFixtures;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/**
 * SECURITY.md S7, Module 3: a receipt attached to a bill (063). Its type comes from its content (a page named .pdf is
 * refused, a PDF with a script is refused, a photo named .exe is kept as a .jpg); it is sent only by the API, after the
 * bill's permission, as a download that is never sniffed, cached or run; it is removed, not deleted; another company
 * never reaches it.
 */
final class AttachmentTest extends TestCase
{
    use CompanyFixtures;
    use RefreshLedgerDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function test_a_receipt_is_kept_by_its_content_and_sent_only_after_the_bills_permission(): void
    {
        config(['einvoicing.force_sandbox' => true, 'queue.default' => 'sync']);
        $b = $this->companyWithRecords('b@example.test', 'Blue Nile Traders');
        $c = $this->companyWithRecords('owner@example.test', 'Abyssinia Coffee');
        $h = ['X-Company-Id' => $c['company'], 'Accept' => 'application/json'];
        $attach = fn (string $name, string $bytes, ?string $bill = null) => $this->post('/api/v1/bills/' . ($bill ?? $c['bill']) . '/attachments',
            ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], $h);

        $attach('receipt.pdf', '<html><script>alert(1)</script></html>')->assertUnprocessable()->assertJsonValidationErrors('file');
        $attach('receipt.pdf', str_replace('/Catalog', '/Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >>', self::PDF))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $id = $attach('Receipt R-101.pdf', self::PDF)->assertCreated()->assertJsonPath('content_type', 'application/pdf')
            ->assertJsonPath('file_name', 'Receipt R-101.pdf')->json('id');
        $photo = $attach('photo.exe', "\xFF\xD8\xFF\xE0" . str_repeat('x', 40))->assertCreated()->assertJsonPath('content_type', 'image/jpeg')
            ->assertJsonPath('file_name', 'photo.jpg');
        $this->getJson("/api/v1/bills/{$c['bill']}/attachments", $h)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.added_by', 'Abyssinia Coffee')
            ->assertJsonPath('can.add', true);

        // Kept outside the web root under a random name; sent back exactly, as a download that does not run.
        $key = (string) $this->owner()->table('attachments')->where('id', $id)->value('storage_key');
        $this->assertFileExists(config('attachments.root') . DIRECTORY_SEPARATOR . $c['company'] . DIRECTORY_SEPARATOR . $key);
        $this->assertStringNotContainsString(base_path('public'), (string) config('attachments.root'));
        $file = $this->get("/api/v1/attachments/{$id}", $h)->assertOk();
        $this->assertSame(self::PDF, $file->streamedContent());
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", (string) $file->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('attachment', (string) $file->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));

        // Another company: not its bill, not its file.
        $this->actingAs(User::query()->findOrFail($b['user']), 'sanctum');
        $hb = ['X-Company-Id' => $b['company'], 'Accept' => 'application/json'];
        $this->get("/api/v1/attachments/{$id}", $hb)->assertNotFound();
        $this->getJson("/api/v1/bills/{$c['bill']}/attachments", $hb)->assertNotFound();
        $this->post("/api/v1/bills/{$c['bill']}/attachments", ['file' => UploadedFile::fake()->createWithContent('x.pdf', self::PDF)], $hb)->assertNotFound();
        $this->actingAs(User::query()->findOrFail($c['user']), 'sanctum');

        // Removed, not deleted: no longer listed or sent, the file and its record kept.
        $this->postJson("/api/v1/attachments/{$id}/remove", [], $h)->assertOk();
        $this->getJson("/api/v1/bills/{$c['bill']}/attachments", $h)->assertJsonCount(1, 'data');
        $this->get("/api/v1/attachments/{$id}", $h)->assertNotFound();
        $this->assertNotNull($this->owner()->table('attachments')->where('id', $id)->value('removed_at'));
        $this->assertSame(['attachment_added', 'attachment_added', 'attachment_removed'], $this->owner()->table('audit_log')
            ->where(['company_id' => $c['company'], 'subject_id' => $c['bill']])->where('action', 'like', 'attachment_%')->orderBy('id')->pluck('action')->all());
        $this->assertNotEmpty($photo->json('id'));
    }
}
