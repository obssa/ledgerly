<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;

/**
 * A document's history for the screen: every saved version (before each correction) and every audit record about
 * it, newest first, each with the name of who did it. Names only: no other sign-in details leave the users table.
 */
final class DocumentHistory
{
    /** @return array{versions: list<array<string, mixed>>, audit: list<array<string, mixed>>} */
    public function for(Document $document): array
    {
        $versions = DocumentVersion::query()->where('document_id', $document->getKey())->orderByDesc('version')->get();
        // Always name the company, even though row-level security would filter it anyway.
        $audit = DB::table('audit_log')
            ->where('company_id', $document->company_id)
            ->where('subject_id', $document->getKey())
            ->orderByDesc('id')
            ->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']);

        $ids = $versions->pluck('edited_by')->merge($audit->pluck('user_id'))->filter()->unique()->values();
        $names = $ids->isEmpty() ? collect() : DB::table('users')->whereIn('id', $ids->all())->pluck('name', 'id');

        return [
            'versions' => $versions->map(fn (DocumentVersion $v) => [...$v->toArray(), 'edited_by_name' => $names[$v->edited_by] ?? null])->all(),
            'audit' => $audit->map(fn ($row) => [...(array) $row, 'user_name' => $row->user_id ? ($names[$row->user_id] ?? null) : null])->all(),
        ];
    }
}
