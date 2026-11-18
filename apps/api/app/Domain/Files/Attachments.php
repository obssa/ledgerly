<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Files attached to documents (SECURITY.md S7, schema 063). The type is decided by the file's content, never by its
 * name or the browser's word: a PDF, PNG, JPEG or WebP, 10 MB at most; a PDF that carries scripts, launch actions or
 * embedded files is refused. The file is written to private storage outside the web root under a random key, readable
 * only by the API's user, and the database records what it is. A file is never run, resized or rewritten.
 */
final class Attachments
{
    public const TYPES = ['application/pdf' => 'PDF', 'image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** The type by the file's first bytes, or null when it is none of the four kept. */
    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** A PDF that could run something when opened: JavaScript, a launch action, embedded files or rich media. */
    public static function pdfRunsSomething(string $bytes): bool
    {
        return preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA)\b#', $bytes) === 1;
    }

    /** The extension a file of each type is saved under, whatever its name said. */
    public const EXTENSIONS = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * A name to show and to download under: no folders or control characters, 160 characters at most, and the extension
     * of what the file is (a photo named receipt.exe is saved as receipt.jpg).
     */
    public static function cleanName(string $name, string $type): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', basename(str_replace('\\', '/', $name))) ?? '');
        $stem = trim((string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name), ' .');
        $stem = mb_substr($stem === '' ? 'attachment' : $stem, 0, 150);

        return $stem . '.' . self::EXTENSIONS[$type];
    }

    public function path(string $companyId, string $key): string
    {
        return rtrim((string) config('attachments.root'), '/\\') . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . $key;
    }

    /** @return array<string, mixed> the attachment as listed */
    public function store(Document $document, UploadedFile $file, string $userId): array
    {
        $max = (int) config('attachments.max_bytes');
        $size = (int) $file->getSize();
        if (! $file->isValid() || $size < 1 || $size > $max) {
            throw ValidationException::withMessages(['file' => ['A file of 1 byte to ' . intdiv($max, 1024 * 1024) . ' MB.']]);
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $type = self::detect($bytes);
        if ($type === null) {
            throw ValidationException::withMessages(['file' => ['Attach a PDF, PNG, JPEG or WebP file (a scan or a photo of the receipt).']]);
        }
        if ($type === 'application/pdf' && self::pdfRunsSomething($bytes)) {
            throw ValidationException::withMessages(['file' => ['This PDF carries scripts or embedded files and is not kept: print it to PDF again, or attach a photo.']]);
        }

        $key = bin2hex(random_bytes(20));
        $path = $this->path($document->company_id, $key);
        $folder = dirname($path);
        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('The attachment folder could not be made.');
        }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($path);
            throw new RuntimeException('The attachment could not be written.');
        }
        @chmod($path, 0600);

        $id = (string) Str::uuid7();
        $row = ['id' => $id, 'company_id' => $document->company_id, 'document_id' => $document->getKey(), 'file_name' => self::cleanName($file->getClientOriginalName(), $type),
            'content_type' => $type, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'storage_key' => $key, 'created_by' => $userId];
        try {
            DB::table('attachments')->insert($row);
        } catch (\Throwable $e) {
            @unlink($path);   // nothing stays on disk that the database does not know
            if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() === '23514' && str_contains($e->getMessage(), 'at most 20')) {
                throw ValidationException::withMessages(['file' => ['A document keeps at most 20 attachments: remove one first.']]);
            }
            throw $e;
        }
        $this->audit->record($document->company_id, 'attachment_added', (string) $document->type, $document->getKey(), null,
            ['attachment' => $id, 'file_name' => $row['file_name'], 'content_type' => $type, 'size_bytes' => $row['size_bytes'], 'sha256' => $row['sha256']], userId: $userId);

        return ['id' => $id, 'file_name' => $row['file_name'], 'content_type' => $type, 'size_bytes' => $row['size_bytes']];
    }
}
