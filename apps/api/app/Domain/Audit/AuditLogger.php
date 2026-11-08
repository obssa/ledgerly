<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Writes to the append-only, hash-chained audit_log (database/schema/006_history.sql).
 * The database assigns the id, timestamp and hash; nothing here can rewrite earlier rows.
 */
final class AuditLogger
{
    public function record(
        string $companyId,
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $userId = null,
        string $actorType = 'user',
    ): void {
        $request = app()->bound('request') ? request() : null;

        DB::table('audit_log')->insert([
            'company_id' => $companyId,
            'user_id' => $userId,
            'actor_type' => $userId ? $actorType : 'system',
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'ip' => $request?->ip(),
            'device' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /** Only the fields whose values differ, as [field => [old, new]]. */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ((string) json_encode($old) !== (string) json_encode($new)) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }
}
