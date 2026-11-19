<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\BankRules;
use App\Domain\Banking\Models\BankTransaction;
use App\Domain\Banking\StatementFormats;
use App\Domain\Ledger\ExchangeRates;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Contact;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5, statements: import a bank, wallet or cash statement and review each line. A line is matched to the entry
 * already in the books (a payment, a transfer, an expense), categorised (a new entry against the account it belongs to,
 * such as bank charges or interest), or excluded with a reason. Every step can be undone until the line is reconciled;
 * undoing a categorised line reverses the entry it made. The statement file itself is never stored.
 */
final class StatementController extends Controller
{
    /** Days either side of the bank's date an entry can be and still be suggested as the match. */
    public const MATCH_WINDOW_DAYS = 7;

    /** Accounts a statement line is never categorised to: these have their own documents (invoices, bills, payments, stock). */
    private const NOT_FOR_CATEGORISING = ['receivable', 'payable', 'inventory', 'opening_balance', 'retained_earnings'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly AuditLogger $audit,
        private readonly PostingService $posting,
        private readonly StatementFormats $formats,
        private readonly ExchangeRates $rates,
        private readonly BankRules $rules,
    ) {
    }

    /** The account's statement lines, newest first; lines still to review carry up to three suggested matches. */
    public function index(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $q = $request->validate(['status' => ['nullable', Rule::in([...BankTransaction::STATUSES, 'all'])]]);
        $status = $q['status'] ?? 'imported';

        $lines = BankTransaction::query()->where('account_id', $bankAccount->getKey())
            ->when($status !== 'all', fn ($b) => $b->where('status', $status))
            ->orderByDesc('posted_on')->orderBy('created_at')->orderBy('id')->limit(500)->get();
        $counts = BankTransaction::query()->where('account_id', $bankAccount->getKey())->groupBy('status')
            ->select('status')->selectRaw('count(*) AS n')->pluck('n', 'status');

        $explained = $this->entriesFor($lines->pluck('journal_line_id')->filter()->values()->all());
        $suggestions = $this->suggestions($bankAccount, $lines->where('status', 'imported'));
        $rules = $this->rules->active();   // 076: the bank rule that fits each line to review

        return response()->json([
            'account' => ['id' => $bankAccount->getKey(), 'code' => $bankAccount->code, 'name' => $bankAccount->name, 'currency' => $this->currencyOf($bankAccount)],
            'status' => $status,
            'counts' => collect(BankTransaction::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'data' => $lines->map(fn (BankTransaction $t) => [
                ...$this->present($t),
                'entry' => $t->journal_line_id ? ($explained[$t->journal_line_id] ?? null) : null,
                'suggestions' => $suggestions[$t->getKey()] ?? [],
                'rule' => $t->status === 'imported' && ($r = $this->rules->fit($t, $rules)) !== null ? BankRules::present($r) : null,
            ])->values(),
            'can' => ['review' => (bool) $this->current->membership()?->can('banking.review')],
        ]);
    }

    /**
     * Import a statement exported as CSV, OFX or QFX, MT940 or camt.053 (the form is told by the text). Lines already imported
     * (the same statement twice, overlapping months) are skipped.
     */
    public function import(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate([
            'csv' => ['required', 'string', 'max:2000000'],
            'file_name' => ['nullable', 'string', 'max:200'],
        ]);
        if ($bankAccount->system_key === 'undeposited_funds' || ! in_array($bankAccount->subtype, BankAccountController::KINDS, true) || ! $bankAccount->is_active) {
            throw ValidationException::withMessages(['csv' => ["{$bankAccount->name} has no statement of its own: import into the active bank, wallet or cash account the money is in."]]);
        }

        $parsed = $this->formats->parse($data['csv'], $this->current->today());   // CSV, OFX/QFX, MT940 or camt.053
        $lines = collect($parsed['lines']);
        $known = BankTransaction::query()->where('account_id', $bankAccount->getKey())
            ->whereIn('external_id', $lines->pluck('external_id')->all())->pluck('external_id')->flip();
        $new = $lines->reject(fn (array $l) => isset($known[$l['external_id']]));

        $userId = $request->user()->getKey();
        DB::transaction(function () use ($new, $bankAccount, $userId) {
            foreach ($new as $line) {
                BankTransaction::query()->create([
                    'account_id' => $bankAccount->getKey(), 'posted_on' => $line['posted_on'], 'description' => $line['description'],
                    'amount' => $line['amount'], 'balance_after' => $line['balance_after'], 'source' => 'statement',
                    'external_id' => $line['external_id'], 'reference' => $line['reference'], 'imported_by' => $userId,
                ]);
            }
        });

        $summary = [
            'file_name' => $data['file_name'] ?? null, 'format' => $parsed['format'], 'imported' => $new->count(), 'skipped' => $lines->count() - $new->count(),
            'from' => $lines->min('posted_on'), 'to' => $lines->max('posted_on'), 'columns' => $parsed['columns'],
        ];
        $this->audit->record($this->current->id(), 'statement_imported', 'bank_account', $bankAccount->getKey(), null,
            array_diff_key($summary, ['columns' => true]), userId: $userId);

        return response()->json($summary, 201);
    }

    /** Explain the line with an entry already in the books: on this account, for the same amount, not explaining another line. */
    public function match(Request $request, BankTransaction $statementLine): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate(['journal_line_id' => ['required', 'uuid']]);
        $this->assertToReview($statementLine);
        $account = Account::query()->findOrFail($statementLine->account_id);

        $line = $this->openLines($account)->where('l.id', $data['journal_line_id'])->first();
        if ($line === null) {
            throw ValidationException::withMessages(['journal_line_id' => ["Choose a posted entry on {$account->name} that no other statement line is matched to."]]);
        }
        $amount = $this->amountOn($account, $line);
        if ($amount !== $this->decimal($statementLine->amount)) {
            throw ValidationException::withMessages(['journal_line_id' => ["Entry #{$line->entry} is for {$amount}; the bank line is for {$this->decimal($statementLine->amount)}."]]);
        }

        $this->explain($statementLine, 'matched', $line->id);
        $this->audit->record($this->current->id(), 'statement_line_matched', 'bank_transaction', $statementLine->getKey(), null,
            ['journal_entry' => $line->entry, 'amount' => $amount, 'bank_date' => $statementLine->posted_on, 'entry_date' => $line->entry_date],
            userId: $request->user()->getKey());

        return $this->show($statementLine);
    }

    /** Explain the line with a new entry: the bank account against the account the money belongs to (bank charges, interest ...). */
    public function categorise(Request $request, BankTransaction $statementLine): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'memo' => ['nullable', 'string', 'max:255'],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],   // a foreign-currency account's line: base per unit; else the day's rate
        ]);
        $this->assertToReview($statementLine);
        $target = Account::query()->where('is_active', true)->find($data['account_id'])
            ?? throw ValidationException::withMessages(['account_id' => ['Choose another active account of this company.']]);
        $contact = isset($data['contact_id']) ? Contact::query()->find($data['contact_id']) : null;
        if (isset($data['contact_id']) && $contact === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer or supplier of this company.']]);
        }
        $this->categoriseLine($statementLine, $target, $contact, $data['memo'] ?? null, $data['fx_rate'] ?? null, $request->user()->getKey());

        return $this->show($statementLine);
    }

    /**
     * 076: every line to review on the account that a bank rule fits is categorised by it, each through the same entry as
     * categorising it by hand. A line the rule cannot categorise (no rate for its currency, an account no longer usable)
     * stays to review, with the reason.
     */
    public function applyRules(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $rules = $this->rules->active();
        $lines = BankTransaction::query()->where(['account_id' => $bankAccount->getKey(), 'status' => 'imported'])->orderBy('posted_on')->orderBy('id')->limit(500)->get();
        $done = 0;
        $skipped = [];
        foreach ($lines as $line) {
            $r = $this->rules->fit($line, $rules);
            if ($r === null) {
                continue;
            }
            $target = Account::query()->where('is_active', true)->find($r->target_account_id);
            $contact = $r->contact_id ? Contact::query()->find($r->contact_id) : null;
            try {
                if ($target === null) {
                    throw ValidationException::withMessages(['account_id' => ["{$r->target_name} is no longer an active account."]]);
                }
                $this->categoriseLine($line, $target, $contact, $r->memo, null, $request->user()->getKey(), (string) $r->name);
                $done++;
            } catch (ValidationException $e) {
                $skipped[] = ['line_id' => $line->getKey(), 'rule' => $r->name, 'reason' => collect($e->errors())->flatten()->first()];
            } catch (\App\Domain\Ledger\Exceptions\LedgerException $e) {
                $skipped[] = ['line_id' => $line->getKey(), 'rule' => $r->name, 'reason' => $e->getMessage()];   // a closed period, say
            }
        }

        return response()->json(['categorised' => $done, 'skipped' => $skipped]);
    }

    /** One line categorised: a new entry, Dr or Cr the bank, the other side to the account given (027). */
    private function categoriseLine(BankTransaction $statementLine, Account $target, ?Contact $contact, ?string $memoGiven, ?string $fxRate, string $userId, ?string $rule = null): void
    {
        $this->categoriseParts($statementLine, [['account' => $target, 'contact' => $contact, 'memo' => $memoGiven, 'amount' => null]], $fxRate, $userId, $rule);
    }

    /**
     * 077: a statement line split over several accounts: one entry, the whole line on the bank, each part on its own
     * account (with its own contact and memo); the parts add up exactly to the line. A part with no amount is the
     * whole line. In a foreign currency each part is converted at the line's rate, the last taking what rounding leaves.
     *
     * @param list<array{account: Account, contact: ?Contact, memo: ?string, amount: ?string}> $parts
     */
    private function categoriseParts(BankTransaction $statementLine, array $parts, ?string $fxRate, string $userId, ?string $rule = null): void
    {
        $company = $this->current->get();
        $bank = Account::query()->findOrFail($statementLine->account_id);
        $currency = $this->currencyOf($bank);
        foreach ($parts as $n => $part) {
            $target = $part['account'];
            $field = count($parts) === 1 ? 'account_id' : "parts.{$n}.account_id";
            if ($target->getKey() === $bank->getKey()) {
                throw ValidationException::withMessages([$field => ['Choose another active account of this company.']]);
            }
            // A bank, cash or wallet account without a currency of its own is in the base currency; other accounts take any.
            $targetCurrency = in_array($target->subtype, ['bank', 'cash', 'mobile_money'], true) ? $this->currencyOf($target) : $target->currency;
            if ($targetCurrency !== null && $targetCurrency !== $currency) {
                throw ValidationException::withMessages([$field => ["{$target->name} is kept in {$targetCurrency}: a {$currency} line goes to an account in {$currency} or with no currency of its own."]]);
            }
            if (in_array($target->subtype, self::NOT_FOR_CATEGORISING, true)) {
                throw ValidationException::withMessages([$field => ["{$target->name} is kept by its own documents: record a payment, bill or stock entry and match the line to it instead."]]);
            }
        }

        // A foreign-currency account's line is in its currency, entered at the day's rate (or the one given).
        $base = $company->base_currency;
        $rate = $currency === $base ? '1' : $this->rates->forDocument($company, $currency, substr((string) $statementLine->posted_on, 0, 10), $fxRate);
        $amount = Money::of($this->decimal($statementLine->amount), $currency);
        $in = ! $amount->isNegative();
        $txn = $in ? $amount : $amount->negated();
        $size = $currency === $base ? $txn : $txn->convertTo($base, $rate)->roundToCurrency();
        $given = array_reduce($parts, fn (Money $sum, array $p) => $sum->plus(Money::of($p['amount'] ?? $txn->amount, $currency)), Money::zero($currency));
        if (! $given->minus($txn)->isZero()) {
            throw ValidationException::withMessages(['parts' => ["The parts add up to {$given->toDecimalString()}; the line is {$txn->toDecimalString()}."]]);
        }

        $memoOf = fn (array $p) => $p['memo'] ?? $statementLine->description;
        $draft = new JournalDraft($company->getKey(), new DateTimeImmutable($statementLine->posted_on), $base, $currency,
            $rate, mb_substr(count($parts) === 1 ? $memoOf($parts[0]) : $statementLine->description, 0, 255), 'bank_transaction', $statementLine->getKey());
        $single = count($parts) === 1 ? $parts[0]['contact']?->getKey() : null;
        $draft->add($in ? JournalLineDraft::debit($bank->getKey(), $size, $txn, $statementLine->description, $single)
            : JournalLineDraft::credit($bank->getKey(), $size, $txn, $statementLine->description, $single));
        $left = $size;
        $audit = [];
        foreach (array_values($parts) as $n => $p) {
            $partTxn = Money::of($p['amount'] ?? $txn->amount, $currency);
            $partBase = $n === count($parts) - 1 ? $left : ($currency === $base ? $partTxn : $partTxn->convertTo($base, $rate)->roundToCurrency());
            $left = $left->minus($partBase);
            $contact = $p['contact']?->getKey();
            $draft->add($in ? JournalLineDraft::credit($p['account']->getKey(), $partBase, $partTxn, $memoOf($p), $contact)
                : JournalLineDraft::debit($p['account']->getKey(), $partBase, $partTxn, $memoOf($p), $contact));
            $audit[] = ['account' => "{$p['account']->code} {$p['account']->name}", 'amount' => $partTxn->toDecimalString()];
        }

        DB::transaction(function () use ($draft, $userId, $statementLine, $bank, $amount, $size, $rule, $audit) {
            $entry = $this->posting->post($draft, $userId);
            $bankLine = DB::table('journal_lines')->where(['company_id' => $entry->company_id, 'journal_entry_id' => $entry->getKey(), 'account_id' => $bank->getKey()])->value('id');
            $this->explain($statementLine, 'categorised', $bankLine);
            $this->audit->record($this->current->id(), 'statement_line_categorised', 'bank_transaction', $statementLine->getKey(), null,
                [...(count($audit) === 1 ? ['account' => $audit[0]['account']] : ['parts' => $audit]), 'amount' => $amount->toDecimalString(), 'currency' => $amount->currency,
                    'base' => $size->toDecimalString(), 'journal_entry' => $entry->number, ...($rule !== null ? ['rule' => $rule] : [])], userId: $userId);
        });
    }

    /** 077: a statement line split over several accounts in one entry; the parts add up to the line. */
    public function split(Request $request, BankTransaction $statementLine): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:2', 'max:20'],
            'parts.*.account_id' => ['required', 'uuid'],
            'parts.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
            'parts.*.contact_id' => ['nullable', 'uuid'],
            'parts.*.memo' => ['nullable', 'string', 'max:255'],
            'fx_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
        ]);
        $this->assertToReview($statementLine);
        $parts = [];
        foreach ($data['parts'] as $n => $p) {
            // Loaded through the company-scoped models (CLAUDE.md rule 4).
            $account = Account::query()->where('is_active', true)->find($p['account_id'])
                ?? throw ValidationException::withMessages(["parts.{$n}.account_id" => ['Choose an active account of this company.']]);
            $contact = isset($p['contact_id']) ? (Contact::query()->find($p['contact_id'])
                ?? throw ValidationException::withMessages(["parts.{$n}.contact_id" => ['Choose a customer or supplier of this company.']])) : null;
            $parts[] = ['account' => $account, 'contact' => $contact, 'memo' => $p['memo'] ?? null, 'amount' => (string) $p['amount']];
        }
        $this->categoriseParts($statementLine, $parts, $data['fx_rate'] ?? null, $request->user()->getKey());

        return $this->show($statementLine);
    }

    /** Leave the line out of the books, saying why: a duplicate, a line of another account, a hold the bank released. */
    public function exclude(Request $request, BankTransaction $statementLine): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->assertToReview($statementLine);

        $statementLine->status = 'excluded';
        $statementLine->note = $data['reason'];
        $statementLine->save();
        $this->audit->record($this->current->id(), 'statement_line_excluded', 'bank_transaction', $statementLine->getKey(), null,
            ['amount' => $this->decimal($statementLine->amount)], $data['reason'], userId: $request->user()->getKey());

        return $this->show($statementLine);
    }

    /** Back to review. A categorised line's entry is reversed on its own date; a reconciled line stays as it is. */
    public function undo(Request $request, BankTransaction $statementLine): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        if (! in_array($statementLine->status, ['matched', 'categorised', 'excluded'], true)) {
            throw ValidationException::withMessages(['status' => [$statementLine->status === 'reconciled'
                ? 'This line is reconciled: reopen the reconciliation first.' : 'This line is still to review.']]);
        }
        $before = ['status' => $statementLine->status, 'note' => $statementLine->note];
        $entryId = $statementLine->status === 'categorised'
            ? DB::table('journal_lines')->where(['company_id' => $this->current->id(), 'id' => $statementLine->journal_line_id])->value('journal_entry_id') : null;

        $userId = $request->user()->getKey();
        DB::transaction(function () use ($statementLine, $entryId, $userId, $before, $data) {
            $statementLine->status = 'imported';
            $statementLine->journal_line_id = null;
            $statementLine->note = null;
            $statementLine->save();
            $reversal = $entryId ? $this->posting->reverse($entryId, new DateTimeImmutable($statementLine->posted_on), $userId) : null;
            $this->audit->record($this->current->id(), 'statement_line_reopened', 'bank_transaction', $statementLine->getKey(), $before,
                ['status' => 'imported', 'reversal' => $reversal?->number], $data['reason'] ?? null, userId: $userId);
        });

        return $this->show($statementLine);
    }

    private function show(BankTransaction $line): JsonResponse
    {
        $line->refresh();

        return response()->json([...$this->present($line), 'entry' => $line->journal_line_id ? ($this->entriesFor([$line->journal_line_id])[$line->journal_line_id] ?? null) : null]);
    }

    private function assertToReview(BankTransaction $line): void
    {
        if ($line->status !== 'imported') {
            throw ValidationException::withMessages(['status' => ["This line is already {$line->status}: undo that first."]]);
        }
    }

    private function explain(BankTransaction $line, string $status, string $journalLineId): void
    {
        $line->status = $status;
        $line->journal_line_id = $journalLineId;
        try {
            DB::transaction(fn () => $line->save());
        } catch (QueryException $e) {
            // Another statement line took this entry a moment ago (bank_transactions_one_line_each, 027).
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['journal_line_id' => ['Another statement line was matched to this entry a moment ago.']]);
            }
            throw $e;
        }
    }

    /**
     * Posted entries on the account that no statement line explains yet; reversed entries and reversals are left out,
     * because together they never moved money.
     */
    private function openLines(Account $account): \Illuminate\Database\Query\Builder
    {
        $companyId = $this->current->id();

        return DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where(['l.company_id' => $companyId, 'l.account_id' => $account->getKey(), 'e.status' => 'posted'])
            ->where('e.source_type', '<>', 'reversal')
            ->whereNotExists(fn ($q) => $q->from('bank_transactions as bt')->whereColumn('bt.company_id', 'l.company_id')->whereColumn('bt.journal_line_id', 'l.id'))
            ->select(['l.id', 'l.entry_date', 'l.debit', 'l.credit', 'l.amount_txn', 'l.description', 'e.number as entry', 'e.memo', 'e.source_type', 'c.name as contact']);
    }

    /**
     * Up to three entries per line to review: same account, same amount, within a week of the bank's date; an entry whose
     * memo carries the bank's reference first, then the closest date.
     *
     * @param Collection<int, BankTransaction> $lines
     * @return array<string, list<array<string, mixed>>>
     */
    private function suggestions(Account $account, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }
        $window = self::MATCH_WINDOW_DAYS;
        $from = (new DateTimeImmutable((string) $lines->min('posted_on')))->modify("-{$window} days")->format('Y-m-d');
        $to = (new DateTimeImmutable((string) $lines->max('posted_on')))->modify("+{$window} days")->format('Y-m-d');
        $open = $this->openLines($account)->whereBetween('l.entry_date', [$from, $to])->orderBy('l.entry_date')->limit(5000)->get()
            ->groupBy(fn ($l) => $this->amountOn($account, $l));

        $out = [];
        foreach ($lines as $t) {
            $bankDate = new DateTimeImmutable($t->posted_on);
            $out[$t->getKey()] = collect($open[$this->decimal($t->amount)] ?? [])
                ->map(fn ($l) => [$l, (int) abs((int) $bankDate->diff(new DateTimeImmutable($l->entry_date))->format('%r%a'))])
                ->filter(fn ($p) => $p[1] <= $window)
                ->sortBy(fn ($p) => [($t->reference && str_contains(($p[0]->memo ?? '') . ' ' . ($p[0]->description ?? ''), $t->reference)) ? 0 : 1, $p[1]])
                ->take(3)
                ->map(fn ($p) => ['journal_line_id' => $p[0]->id, 'entry' => $p[0]->entry, 'date' => $p[0]->entry_date, 'memo' => $p[0]->memo,
                    'description' => $p[0]->description, 'contact' => $p[0]->contact, 'source_type' => $p[0]->source_type, 'days_apart' => $p[1]])
                ->values()->all();
        }

        return $out;
    }

    /** @param list<string> $ids @return array<string, array<string, mixed>> the entry each journal line belongs to */
    private function entriesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->where('l.company_id', $this->current->id())->whereIn('l.id', $ids)
            ->get(['l.id', 'e.id as entry_id', 'e.number', 'e.entry_date', 'e.memo', 'e.source_type', 'e.status'])
            ->mapWithKeys(fn ($r) => [$r->id => ['id' => $r->entry_id, 'number' => $r->number, 'date' => $r->entry_date, 'memo' => $r->memo,
                'source_type' => $r->source_type, 'status' => $r->status]])->all();
    }

    /** The journal line's amount in the account's own currency, signed like the statement (+ in, - out). */
    private function amountOn(Account $account, object $line): string
    {
        $base = $this->current->get()->base_currency;
        if ($this->currencyOf($account) !== $base) {
            return $this->decimal((string) $line->amount_txn);
        }

        return Money::of((string) $line->debit, $base)->minus(Money::of((string) $line->credit, $base))->toDecimalString();
    }

    private function currencyOf(Account $account): string
    {
        return $account->currency ?? $this->current->get()->base_currency;
    }

    private function decimal(string $value): string
    {
        return (string) \Brick\Math\BigDecimal::of($value)->toScale(2);
    }

    /** @return array<string, mixed> */
    private function present(BankTransaction $t): array
    {
        return [
            'id' => $t->getKey(), 'account_id' => $t->account_id, 'date' => $t->posted_on, 'description' => $t->description,
            'reference' => $t->reference, 'amount' => $this->decimal($t->amount), 'balance_after' => $t->balance_after === null ? null : $this->decimal($t->balance_after),
            'status' => $t->status, 'note' => $t->note, 'source' => $t->source,
        ];
    }
}
