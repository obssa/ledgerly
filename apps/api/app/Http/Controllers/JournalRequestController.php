<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ManualJournal;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 6: manual journals above the company's approval limit, waiting for someone other than the person who asked.
 * Approved, the journal is checked again and posted as it was asked, by the approver; rejected, with a note; or
 * withdrawn by the person who asked. The database keeps these rules too (033).
 */
final class JournalRequestController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'withdrawn', 'all'])]])['status'] ?? 'pending';
        $ids = DB::table('journal_requests')->where('company_id', $this->current->id())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->limit(200)->pluck('id');

        return response()->json([
            'status' => $status,
            'data' => $ids->map(fn (string $id) => $this->present($id, withLines: false))->values(),
            'pending' => DB::table('journal_requests')->where(['company_id' => $this->current->id(), 'status' => 'pending'])->count(),
        ]);
    }

    public function show(string $journalRequest): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json($this->present($this->load($journalRequest)->id));
    }

    /** Approve: someone other than the person who asked; the journal is checked again and posted as asked. */
    public function approve(Request $request, string $journalRequest, JournalController $journals): JsonResponse
    {
        $this->authorizeCompany('journals.approve');
        $userId = $request->user()->getKey();
        $company = $this->current->get();

        $entry = DB::transaction(function () use ($journalRequest, $userId, $company, $journals) {
            $r = $this->load($journalRequest, lock: true);
            $this->assertPending($r);
            if ($r->requested_by === $userId) {
                throw ValidationException::withMessages(['status' => ['You asked for this journal: someone else approves it.']]);
            }
            $draft = ManualJournal::draft($company, $r->entry_date, $r->memo, json_decode((string) $r->lines, true, 512, JSON_THROW_ON_ERROR), $r->id);
            $entry = $journals->guarded(fn () => $this->posting->post($draft, $userId));
            DB::table('journal_requests')->where('id', $r->id)->update([
                'status' => 'approved', 'decided_by' => $userId, 'decided_at' => now(), 'journal_entry_id' => $entry->getKey(),
            ]);
            $this->audit->record($company->getKey(), 'journal_approved', 'journal_request', $r->id, ['status' => 'pending'],
                ['status' => 'approved', 'journal_entry' => $entry->number, 'total' => (string) $r->total], userId: $userId);

            return $entry;
        });

        return response()->json($journals->present($entry), 201);
    }

    public function reject(Request $request, string $journalRequest): JsonResponse
    {
        $this->authorizeCompany('journals.approve');
        $this->load($journalRequest);   // another company's request is not found, whatever the body says
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']], ['note.required' => 'Say why, so the person who asked can put it right.']);

        return $this->decide($request, $journalRequest, 'rejected', $data['note']);
    }

    /** Withdraw: only the person who asked, while it waits. */
    public function withdraw(Request $request, string $journalRequest): JsonResponse
    {
        $this->authorizeCompany('journals.create');

        return $this->decide($request, $journalRequest, 'withdrawn', null);
    }

    private function decide(Request $request, string $id, string $status, ?string $note): JsonResponse
    {
        $userId = $request->user()->getKey();
        DB::transaction(function () use ($id, $status, $note, $userId) {
            $r = $this->load($id, lock: true);
            $this->assertPending($r);
            if ($status === 'rejected' && $r->requested_by === $userId) {
                throw ValidationException::withMessages(['status' => ['You asked for this journal: withdraw it instead.']]);
            }
            if ($status === 'withdrawn' && $r->requested_by !== $userId) {
                throw ValidationException::withMessages(['status' => ['Only the person who asked withdraws a request.']]);
            }
            DB::table('journal_requests')->where('id', $r->id)->update(['status' => $status, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->record($this->current->id(), "journal_{$status}", 'journal_request', $r->id, ['status' => 'pending'], ['status' => $status], $note, userId: $userId);
        });

        return response()->json($this->present($id));
    }

    private function load(string $id, bool $lock = false): object
    {
        $query = DB::table('journal_requests')->where(['company_id' => $this->current->id(), 'id' => $id]);
        $r = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($r === null) {
            abort(404);
        }

        return $r;
    }

    private function assertPending(object $r): void
    {
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ["This request is already {$r->status}."]]);
        }
    }

    /** @return array<string, mixed> */
    public function present(string $id, bool $withLines = true): array
    {
        $r = $this->load($id);
        $names = DB::table('users')->whereIn('id', array_filter([$r->requested_by, $r->decided_by]))->pluck('name', 'id');
        $lines = json_decode((string) $r->lines, true, 512, JSON_THROW_ON_ERROR);
        $accounts = $withLines ? DB::table('accounts')->where('company_id', $this->current->id())->whereIn('id', array_column($lines, 'account_id'))->get(['id', 'code', 'name'])->keyBy('id') : collect();
        $entry = $r->journal_entry_id ? DB::table('journal_entries')->where(['company_id' => $this->current->id(), 'id' => $r->journal_entry_id])->value('number') : null;
        $membership = $this->current->membership();
        $userId = auth()->id();

        return [
            'id' => $r->id, 'date' => $r->entry_date, 'memo' => $r->memo, 'total' => (string) $r->total, 'status' => $r->status,
            'requested_by' => $names[$r->requested_by] ?? null, 'requested_at' => $r->created_at,
            'decided_by' => $r->decided_by ? ($names[$r->decided_by] ?? null) : null, 'decided_at' => $r->decided_at, 'note' => $r->decision_note,
            'journal_entry' => $r->journal_entry_id ? ['id' => $r->journal_entry_id, 'number' => (int) $entry] : null,
            'lines' => $withLines ? array_map(fn (array $l) => [
                'account_id' => $l['account_id'], 'code' => $accounts[$l['account_id']]->code ?? null, 'account' => $accounts[$l['account_id']]->name ?? null,
                'debit' => $l['debit'] ?? null, 'credit' => $l['credit'] ?? null, 'description' => $l['description'] ?? null,
            ], $lines) : null,
            'can' => [
                'approve' => $r->status === 'pending' && $r->requested_by !== $userId && (bool) $membership?->can('journals.approve'),
                'withdraw' => $r->status === 'pending' && $r->requested_by === $userId,
            ],
        ];
    }
}
