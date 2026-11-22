<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 10/11: the audit log as a report. Every change, post, void, approval and export, with who, when, from where,
 * what it was before and after, and why: newest first, filtered by date, person, action or record, 100 at a time.
 * The log is append-only and hash-chained (005); verifying the chain says whether any row was altered.
 */
final class AuditLogController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /** What the Changes report lists: a document changed after it was made, cancelled or credited. */
    private const DOCUMENT_CHANGES = ['edited_after_posting', 'draft_saved', 'voided', 'credit_note_created', 'supplier_credit_created',
        'bad_debt_written_off', 'bad_debt_reversed', 'period_reopened'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('audit.view');
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'subject_type' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'subject_id' => ['nullable', 'uuid'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'group' => ['nullable', 'in:document_changes'],   // the Changes report: edits, voids and credits of documents
        ]);
        $companyId = $this->current->id();
        $rows = DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $companyId)
            ->when($q['from'] ?? null, fn ($b, $v) => $b->where('a.created_at', '>=', $v))
            ->when($q['to'] ?? null, fn ($b, $v) => $b->where('a.created_at', '<', (new \DateTimeImmutable($v))->modify('+1 day')->format('Y-m-d')))
            ->when($q['user_id'] ?? null, fn ($b, $v) => $b->where('a.user_id', $v))
            ->when($q['action'] ?? null, fn ($b, $v) => $b->where('a.action', $v))
            ->when($q['subject_type'] ?? null, fn ($b, $v) => $b->where('a.subject_type', $v))
            ->when($q['subject_id'] ?? null, fn ($b, $v) => $b->where('a.subject_id', $v))
            ->when(($q['group'] ?? null) === 'document_changes', fn ($b) => $b->whereIn('a.action', self::DOCUMENT_CHANGES))
            ->when($q['before_id'] ?? null, fn ($b, $v) => $b->where('a.id', '<', $v))
            ->orderByDesc('a.id')->limit(101)
            ->get(['a.id', 'a.created_at', 'a.user_id', 'u.name as user_name', 'a.actor_type', 'a.action', 'a.subject_type', 'a.subject_id', 'a.before', 'a.after',
                'a.reason', 'a.ip', 'a.device']);
        $decode = fn ($v) => $v === null ? null : json_decode((string) $v, true);
        // The documents the rows are about, named (number and kind), so a row links to its invoice or bill.
        $documents = DB::table('documents')->where('company_id', $companyId)->whereIn('id', $rows->pluck('subject_id')->filter()->unique()->all())
            ->get(['id', 'type', 'number', 'reference'])->keyBy('id')->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'number' => $d->number ?? $d->reference]);

        return response()->json([
            'data' => $rows->take(100)->map(fn ($r) => [
                'id' => (int) $r->id, 'at' => $r->created_at, 'user' => $r->user_id ? ['id' => $r->user_id, 'name' => $r->user_name] : null,
                'actor_type' => $r->actor_type, 'action' => $r->action, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
                'before' => $decode($r->before), 'after' => $decode($r->after), 'reason' => $r->reason, 'ip' => $r->ip, 'device' => $r->device,
                'document' => $documents[$r->subject_id] ?? null,
            ])->values(),
            'more' => $rows->count() > 100,
            'next_before_id' => $rows->count() > 100 ? (int) $rows->take(100)->last()->id : null,
            // What the filters offer: the actions and kinds of record this company's log holds, and who appears in it.
            'actions' => DB::table('audit_log')->where('company_id', $companyId)->distinct()->orderBy('action')->limit(300)->pluck('action'),
            'subject_types' => DB::table('audit_log')->where('company_id', $companyId)->distinct()->orderBy('subject_type')->limit(100)->pluck('subject_type'),
            'people' => DB::table('audit_log as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $companyId)
                ->distinct()->orderBy('u.name')->limit(300)->get(['u.id', 'u.name']),
        ]);
    }

    /** Check the hash chain: every row as written, none taken out or changed. */
    public function verify(): JsonResponse
    {
        $this->authorizeCompany('audit.view');
        $companyId = $this->current->id();
        $broken = DB::selectOne('SELECT audit_log_verify(?::uuid) AS broken_at', [$companyId])->broken_at;

        return response()->json([
            'intact' => $broken === null, 'broken_at' => $broken === null ? null : (int) $broken,
            'rows' => DB::table('audit_log')->where('company_id', $companyId)->count(), 'checked_at' => now()->toIso8601String(),
        ]);
    }
}
