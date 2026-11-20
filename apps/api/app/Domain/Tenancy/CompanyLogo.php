<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 1 (100): the company's logo, a PNG or JPEG of up to 256 KB, kept as base64 text. Its type is decided by its first bytes, never by
 * the name or the type the browser sent. Read inside the company's context only (row-level security).
 */
final class CompanyLogo
{
    public const MAX_BYTES = 262144;

    /** @return array{content_type: string, image: string}|null */
    public function get(string $companyId): ?array
    {
        $row = DB::table('company_logos')->where('company_id', $companyId)->first(['content_type', 'image_base64']);

        return $row === null ? null : ['content_type' => (string) $row->content_type, 'image' => (string) base64_decode((string) $row->image_base64, true)];
    }

    /** The logo as a data: URI, to put in a page or PDF built here; none without one. */
    public function dataUri(string $companyId): ?string
    {
        $row = DB::table('company_logos')->where('company_id', $companyId)->first(['content_type', 'image_base64']);

        return $row === null ? null : "data:{$row->content_type};base64,{$row->image_base64}";
    }

    /** @return array{content_type: string, size: int} */
    public function store(string $companyId, string $bytes, string $userId): array
    {
        $type = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => throw ValidationException::withMessages(['file' => ['A logo is a PNG or JPEG image.']]),
        };
        if (strlen($bytes) > self::MAX_BYTES || strlen($bytes) < 32) {
            throw ValidationException::withMessages(['file' => ['A logo of 256 KB at most.']]);
        }
        // Kept as base64 text: bound as an ordinary string, no SQL built from the file (CLAUDE.md rule 1).
        DB::table('company_logos')->upsert([['company_id' => $companyId, 'content_type' => $type, 'image_base64' => base64_encode($bytes), 'updated_by' => $userId, 'updated_at' => now()]],
            ['company_id'], ['content_type', 'image_base64', 'updated_by', 'updated_at']);

        return ['content_type' => $type, 'size' => strlen($bytes)];
    }

    public function remove(string $companyId): bool
    {
        return DB::table('company_logos')->where('company_id', $companyId)->delete() > 0;
    }
}
