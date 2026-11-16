<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Models\Dimension;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 6, journal entries: every entry the books hold, from every module, and the accountant's own (manual) journals.
 * A manual journal never touches an account kept by documents (032); it is corrected by reversing it, never edited. An
 * entry made by a document (an invoice, a payment) is corrected through that document instead.
 */
final class JournalController extends Controller
{
    /** Kinds and system accounts a manual journal leaves alone (032 checks the same in the database). */
    public const KEPT_KINDS = ['receivable', 'payable', 'inventory'];

    public const KEPT_KEYS = ['accounts_receivable', 'accounts_payable', 'inventory', 'grni', 'undeposited_funds', 'retained_earnings'];

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

    /** A manual journal: balanced lines on the company's own active accounts, not on accounts kept by documents. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'memo' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.location_id' => ['nullable', 'uuid'],
            'lines.*.class_id' => ['nullable', 'uuid'],
            'lines.*.project_id' => ['nullable', 'uuid'],
        ], ['memo.required' => 'Say what the entry is for: the memo is how it is found again.']);
        $company = $this->current->get();
        $base = $company->base_currency;

        $accounts = Account::query()->whereIn('id', array_unique(array_column($data['lines'], 'account_id')))->get()->keyBy('id');
        $errors = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $account = $accounts[$line['account_id']] ?? null;
            $debit = Money::of($line['debit'] ?? '0', $base);
            $credit = Money::of($line['credit'] ?? '0', $base);
            if ($account === null || ! $account->is_active) {
                $errors["lines.{$i}.account_id"] = ['Choose an active account of this company.'];
            } elseif (in_array($account->subtype, self::KEPT_KINDS, true) || in_array($account->system_key, self::KEPT_KEYS, true)) {
                $errors["lines.{$i}.account_id"] = ["{$account->code} {$account->name} is kept by its documents: use a credit note, a stock adjustment or a deposit instead."];
            } elseif ($account->currency !== null && $account->currency !== $base) {
                $errors["lines.{$i}.account_id"] = ["{$account->code} {$account->name} is kept in {$account->currency}: move money in it with a transfer."];
            }
            if ($debit->isZero() === $credit->isZero()) {
                $errors["lines.{$i}.debit"] = ['A line is a debit or a credit, above zero.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $dimensions = $this->activeDimensions($data['lines']);

        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($data['date']), $base, $base, '1', $data['memo'], 'manual');
        foreach (array_values($data['lines']) as $i => $line) {
            $debit = Money::of($line['debit'] ?? '0', $base);
            $draft->add($debit->isZero()
                ? JournalLineDraft::credit($line['account_id'], Money::of($line['credit'], $base), null, $line['description'] ?? null, null, $dimensions[$i])
                : JournalLineDraft::debit($line['account_id'], $debit, null, $line['description'] ?? null, null, $dimensions[$i]));
        }
        if (! $draft->totalDebit()->equals($draft->totalCredit())) {
            throw ValidationException::withMessages(['lines' => ["Debits ({$draft->totalDebit()->toDecimalString()}) and credits ({$draft->totalCredit()->toDecimalString()}) must be equal."]]);
        }
        $entry = $this->guarded(fn () => $this->posting->post($draft, $request->user()->getKey()));

        return response()->json($this->present($entry), 201);
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
    private function present(JournalEntry $entry): array
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
        ][$type] ?? str_replace('_', ' ', $type);
    }

    /** A rule the database checks (a closed period, a reconciled account, an account kept by documents) as a 422. */
    private function guarded(callable $post): JournalEntry
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

    /** The location, class and project of each line, each an active dimension of its kind in this company. @return list<array<string, string>> */
    private function activeDimensions(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach (Dimension::COLUMNS as $column) {
                if (! empty($line[$column])) {
                    $ids[] = $line[$column];
                }
            }
        }
        $found = Dimension::query()->whereIn('id', array_unique($ids))->where('is_active', true)->get(['id', 'kind'])->keyBy('id');
        $out = [];
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $out[$i] = [];
            foreach (Dimension::COLUMNS as $kind => $column) {
                if (empty($line[$column])) {
                    continue;
                }
                if (($found[$line[$column]]->kind ?? null) !== $kind) {
                    $errors["lines.{$i}.{$column}"] = ["Not an active {$kind} of this company."];
                } else {
                    $out[$i][$column] = $line[$column];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
