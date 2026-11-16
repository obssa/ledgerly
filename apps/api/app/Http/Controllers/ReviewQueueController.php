<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\ReviewQueue;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 6, accountant tools: the review queue of unusual entries. Anyone who reads the books sees it; accountants,
 * approvers, owners and admins mark an entry reviewed (journals.review), once, with an optional note, and it leaves the
 * queue. Nothing is blocked: the queue only says what is worth a second look and why (App\Domain\Ledger\ReviewQueue).
 */
final class ReviewQueueController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly ReviewQueue $queue, private readonly AuditLogger $audit)
    {
    }

    /** The flagged entries of a period (the last 90 days by default), newest first; reviewed ones too when asked. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $today = $this->current->today();
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reviewed' => ['nullable', 'boolean'],
        ]);
        $to = $q['to'] ?? $today;
        $from = $q['from'] ?? (new \DateTimeImmutable($to))->modify('-90 days')->format('Y-m-d');
        if ($from < (new \DateTimeImmutable($to))->modify('-2 years')->format('Y-m-d')) {
            throw ValidationException::withMessages(['from' => ['Review at most two years at a time.']]);
        }

        return response()->json([
            'from' => $from, 'to' => $to,
            ...$this->queue->entries($this->current->get(), $from, $to, (bool) ($q['reviewed'] ?? false)),
            'flags' => ReviewQueue::FLAGS,
            'can' => ['review' => (bool) $this->current->membership()?->can('journals.review')],
        ]);
    }

    /** Mark an entry reviewed, with an optional note; the flags it had are kept with the review. */
    public function review(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeCompany('journals.review');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $flags = $this->queue->flagsOf($this->current->get(), (object) ['id' => $entry->getKey(), 'entry_date' => $entry->entry_date]);
        try {
            DB::transaction(fn () => DB::table('journal_entry_reviews')->insert([
                'company_id' => $this->current->id(), 'journal_entry_id' => $entry->getKey(), 'reviewed_by' => $request->user()->getKey(),
                'flags' => '{' . implode(',', $flags) . '}', 'note' => $note,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['entry' => ["Entry #{$entry->number} is already reviewed."]]);
        }
        $this->audit->record($this->current->id(), 'journal_reviewed', 'journal_entry', $entry->getKey(), null, ['flags' => $flags, 'note' => $note],
            userId: $request->user()->getKey());

        return response()->json(['id' => $entry->getKey(), 'number' => (int) $entry->number, 'flags' => $flags, 'note' => $note], 201);
    }
}
