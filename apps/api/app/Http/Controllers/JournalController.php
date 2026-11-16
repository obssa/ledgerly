<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ManualJournal;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 6, journal entries: every entry the books hold, from every module, and the accountant's own (manual) journals.
 * A manual journal never touches an account kept by documents (032); it is corrected by reversing it, never edited. An
 * entry made by a document (an invoice, a payment) is corrected through that document instead.
 */
final class JournalController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PostingService $posting, private readonly AuditLogger $audit)
    {
    }

    /** Entries newest first, 50 at a time; by date, by source (manual journals or any module), or by words in the memo. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $companyId = $this->current->id();
        $page = (int) ($q['page'] ?? 1);
        $rows = DB::table('journal_entries as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.posted_by')
            ->where('e.company_id', $companyId)
            ->when($q['from'] ?? null, fn ($b, $v) => $b->where('e.entry_date', '>=', $v))
            ->when($q['to'] ?? null, fn ($b, $v) => $b->where('e.entry_date', '<=', $v))
            ->when($q['source'] ?? null, fn ($b, $v) => $b->where('e.source_type', $v))
            ->when($q['search'] ?? null, fn ($b, $v) => $b->where('e.memo', 'ilike', '%' . addcslashes($v, '%_\\') . '%'))
            ->orderByDesc('e.number')->offset(($page - 1) * 50)->limit(51)
            ->get(['e.id', 'e.number', 'e.entry_date', 'e.memo', 'e.source_type', 'e.source_id', 'e.status', 'e.currency', 'u.name as posted_by']);
        $totals = DB::table('journal_lines')->where('company_id', $companyId)->whereIn('journal_entry_id', $rows->pluck('id')->all())
            ->groupBy('journal_entry_id')->select('journal_entry_id')->selectRaw('sum(debit)::numeric(20,2)::text AS total')->pluck('total', 'journal_entry_id');

        return response()->json([
            'data' => $rows->take(50)->map(fn ($e) => [
                'id' => $e->id, 'number' => (int) $e->number, 'date' => $e->entry_date, 'memo' => $e->memo, 'source_type' => $e->source_type,
                'source_id' => $e->source_id, 'status' => $e->status, 'currency' => $e->currency, 'total' => $totals[$e->id] ?? '0.00', 'posted_by' => $e->posted_by,
            ])->values(),
            'page' => $page,
            'more' => $rows->count() > 50,
            'can' => ['create' => (bool) $this->current->membership()?->can('journals.create')],
        ]);
    }

    /** One entry with its lines, who posted it, where it came from, and what reversed it. */
    public function show(JournalEntry $entry): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json($this->present($entry));
    }

    /**
     * A manual journal: balanced lines on the company's own active accounts, not on accounts kept by documents. Above the
     * company's approval limit (settings.approval.journal_above) it is not posted but waits for someone else to approve
     * it (202, the request); otherwise it posts at once (201, the entry).
     *
     * With reverse_on (an accrual, a prepayment), its reversal is posted with it, dated that day: after the journal and
     * within a year of it. A journal waiting for approval keeps the date and reverses when it is approved.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $data = $request->validate([...ManualJournal::rules($this->current->today()), 'reverse_on' => ['nullable', 'date_format:Y-m-d', 'after:date']],
            ['memo.required' => 'Say what the entry is for: the memo is how it is found again.', 'reverse_on.after' => 'A journal reverses on a day after its own date.']);
        $reverseOn = $data['reverse_on'] ?? null;
        if ($reverseOn !== null && $reverseOn > (new DateTimeImmutable($data['date']))->modify('+1 year')->format('Y-m-d')) {
            throw ValidationException::withMessages(['reverse_on' => ['A journal reverses within a year of its date.']]);
        }
        $company = $this->current->get();
        $draft = ManualJournal::draft($company, $data['date'], $data['memo'], $data['lines']);

        $limit = ManualJournal::approvalLimit($company);
        if ($limit !== null && $limit->minus($draft->totalDebit())->isNegative()) {
            $id = (string) Str::uuid7();
            DB::table('journal_requests')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'entry_date' => $data['date'], 'memo' => $data['memo'],
                'lines' => json_encode(array_values($data['lines']), JSON_THROW_ON_ERROR), 'total' => $draft->totalDebit()->toDecimalString(),
                'requested_by' => $request->user()->getKey(), 'reverse_on' => $reverseOn,
            ]);
            $this->audit->record($company->getKey(), 'journal_requested', 'journal_request', $id, null,
                ['memo' => $data['memo'], 'total' => $draft->totalDebit()->toDecimalString(), 'limit' => $limit->toDecimalString(), 'reverse_on' => $reverseOn],
                userId: $request->user()->getKey());

            return response()->json(['request' => app(JournalRequestController::class)->present($id)], 202);
        }
        $entry = $this->guarded(fn () => $this->posting->post($draft, $request->user()->getKey()));
        if ($reverseOn !== null) {
            $this->autoReverse($entry, $reverseOn, $request->user()->getKey());
        }

        return response()->json($this->present($entry->refresh()), 201);
    }

    /** Post the reversal a journal was set up with, dated $on, and record it. */
    public function autoReverse(JournalEntry $entry, string $on, string $userId): JournalEntry
    {
        $reversal = $this->guarded(fn () => $this->posting->reverse($entry->getKey(), new DateTimeImmutable($on), $userId));
        $this->audit->record($this->current->id(), 'journal_reversed', 'journal_entry', $entry->getKey(), ['status' => 'posted'],
            ['status' => 'reversed', 'reversal' => $reversal->number, 'date' => $on], 'Set to reverse when it was entered', userId: $userId);

        return $reversal;
    }

    /** Reverse a manual journal on a day on or after it, saying why. Entries made by documents are reversed through them. */
    public function reverse(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . substr((string) $entry->entry_date, 0, 10), 'before_or_equal:' . $this->current->today()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['date.after_or_equal' => 'A reversal is dated on or after the entry (' . substr((string) $entry->entry_date, 0, 10) . ').']);
        if ($entry->source_type !== 'manual') {
            throw ValidationException::withMessages(['date' => ["Entry #{$entry->number} was made by a {$this->sourceLabel($entry->source_type)}: correct it there (void, reverse or credit it), so the document and the books agree."]]);
        }
        if ($entry->status !== 'posted') {
            throw ValidationException::withMessages(['date' => ["Entry #{$entry->number} is already reversed."]]);
        }
        $reversal = $this->guarded(fn () => $this->posting->reverse($entry->getKey(), new DateTimeImmutable($data['date']), $request->user()->getKey()));
        $this->audit->record($this->current->id(), 'journal_reversed', 'journal_entry', $entry->getKey(), ['status' => 'posted'],
            ['status' => 'reversed', 'reversal' => $reversal->number, 'date' => $data['date']], $data['reason'], userId: $request->user()->getKey());

        return response()->json($this->present($reversal), 201);
    }

    /** @return array<string, mixed> */
    public function present(JournalEntry $entry): array
    {
        $companyId = $this->current->id();
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->leftJoin('dimensions as dl', 'dl.id', '=', 'l.location_id')->leftJoin('dimensions as dc', 'dc.id', '=', 'l.class_id')->leftJoin('dimensions as dp', 'dp.id', '=', 'l.project_id')
            ->where(['l.company_id' => $companyId, 'l.journal_entry_id' => $entry->getKey()])->orderBy('l.line_no')
            ->get(['l.line_no', 'l.account_id', 'a.code', 'a.name as account', 'a.type', 'l.description', 'l.debit', 'l.credit', 'l.amount_txn', 'c.name as contact',
                'dl.name as location', 'dc.name as class', 'dp.name as project']);
        $postedBy = DB::table('users')->where('id', $entry->posted_by)->value('name');
        $reversedBy = DB::table('journal_entries')->where(['company_id' => $companyId, 'source_type' => 'reversal', 'source_id' => $entry->getKey()])->first(['id', 'number', 'entry_date']);
        $reverses = $entry->source_type === 'reversal' && $entry->source_id
            ? DB::table('journal_entries')->where(['company_id' => $companyId, 'id' => $entry->source_id])->first(['id', 'number', 'entry_date']) : null;
        $document = DB::table('documents')->where(['company_id' => $companyId, 'journal_entry_id' => $entry->getKey()])->first(['id', 'type', 'number']);
        $payment = $document === null ? DB::table('payments')->where(['company_id' => $companyId, 'journal_entry_id' => $entry->getKey()])->first(['id', 'direction', 'kind']) : null;

        return [
            'id' => $entry->getKey(), 'number' => (int) $entry->number, 'date' => substr((string) $entry->entry_date, 0, 10), 'memo' => $entry->memo,
            'source_type' => $entry->source_type, 'source_label' => $this->sourceLabel($entry->source_type), 'status' => $entry->status,
            'currency' => $entry->currency, 'fx_rate' => (string) $entry->fx_rate, 'posted_by' => $postedBy, 'posted_at' => $entry->posted_at,
            'lines' => $lines->map(fn ($l) => [
                'line_no' => (int) $l->line_no, 'account_id' => $l->account_id, 'code' => $l->code, 'account' => $l->account, 'type' => $l->type,
                'description' => $l->description, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit, 'amount_txn' => (string) $l->amount_txn,
                'contact' => $l->contact, 'location' => $l->location, 'class' => $l->class, 'project' => $l->project,
            ])->values(),
            'total' => (string) $lines->reduce(fn ($t, $l) => $t->plus((string) $l->debit), \Brick\Math\BigDecimal::zero())->toScale(2),
            'document' => $document ? ['id' => $document->id, 'type' => $document->type, 'number' => $document->number] : null,
            'payment' => $payment ? ['id' => $payment->id, 'direction' => $payment->direction, 'kind' => $payment->kind] : null,
            'reversed_by' => $reversedBy ? ['id' => $reversedBy->id, 'number' => (int) $reversedBy->number, 'date' => $reversedBy->entry_date] : null,
            'reverses' => $reverses ? ['id' => $reverses->id, 'number' => (int) $reverses->number, 'date' => $reverses->entry_date] : null,
            'can' => ['reverse' => $entry->source_type === 'manual' && $entry->status === 'posted' && (bool) $this->current->membership()?->can('journals.create')],
        ];
    }

    private function sourceLabel(string $type): string
    {
        return [
            'manual' => 'manual journal', 'invoice' => 'invoice', 'credit_note' => 'credit note', 'bill' => 'bill', 'expense' => 'expense',
            'vendor_credit' => 'supplier credit', 'payment' => 'payment', 'payment_allocation' => 'payment applied later', 'transfer' => 'transfer',
            'deposit' => 'deposit', 'reversal' => 'reversal', 'opening' => 'opening balances', 'stock_adjustment' => 'stock adjustment',
            'bank_transaction' => 'bank statement line', 'cash_count' => 'cash count', 'bounced_cheque' => 'bounced cheque',
            'closing' => 'year-end close', 'adjustment' => 'audit adjustment',
        ][$type] ?? str_replace('_', ' ', $type);
    }

    /** A rule the database checks (a closed period, a reconciled account, an account kept by documents) as a 422. */
    public function guarded(callable $post): JournalEntry
    {
        try {
            return $post();
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                $message = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The books refused this entry.';
                throw ValidationException::withMessages(['lines' => [$message]]);
            }
            throw $e;
        }
    }
}
