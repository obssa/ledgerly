<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Banking\Models\Reconciliation;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 5, reconciliation: agree a bank, wallet or cash account's books with its statement. Start from where the last
 * reconciliation ended, enter the statement's date and ending balance, tick the entries the statement shows; finish when
 * the difference is zero. The report is saved with it and the period is locked for that account. Only the latest
 * reconciliation can be undone, with a reason. The database checks every one of these rules again (028).
 */
final class ReconciliationController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    /** The account's reconciliations, newest first, with the draft in progress and where the last completed one ended. */
    public function index(Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $all = Reconciliation::query()->where('account_id', $bankAccount->getKey())->orderByDesc('statement_date')->orderByDesc('created_at')->limit(200)->get();
        $last = $all->firstWhere('status', 'completed');

        return response()->json([
            'account' => $this->accountOf($bankAccount),
            'data' => $all->map(fn (Reconciliation $r) => $this->summary($r))->values(),
            'draft_id' => $all->firstWhere('status', 'draft')?->getKey(),
            'reconciled_through' => $last?->statement_date,
            'next_opening' => $last ? $this->decimal($last->ending_balance) : '0.00',
            'can' => ['reconcile' => $this->canReconcile()],
        ]);
    }

    /** Start reconciling a statement. Entries the statement's lines are already matched to are ticked for you. */
    public function store(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.reconcile');
        $data = $request->validate([
            'statement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'ending_balance' => ['required', 'decimal:0,2'],
        ]);
        if ($bankAccount->system_key === 'undeposited_funds' || ! in_array($bankAccount->subtype, BankAccountController::KINDS, true)) {
            throw ValidationException::withMessages(['statement_date' => ["{$bankAccount->name} has no bank statement to reconcile."]]);
        }
        $existing = Reconciliation::query()->where(['account_id' => $bankAccount->getKey(), 'status' => 'draft'])->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['statement_date' => ["A reconciliation to {$existing->statement_date} is in progress: finish or discard it first."]]);
        }
        $last = Reconciliation::query()->where(['account_id' => $bankAccount->getKey(), 'status' => 'completed'])->orderByDesc('statement_date')->first();
        if ($last !== null && $data['statement_date'] <= $last->statement_date) {
            throw ValidationException::withMessages(['statement_date' => ["{$bankAccount->name} is reconciled through {$last->statement_date}: the next statement ends after that."]]);
        }

        $userId = $request->user()->getKey();
        $reconciliation = DB::transaction(function () use ($bankAccount, $data, $last, $userId) {
            $r = Reconciliation::query()->create([
                'account_id' => $bankAccount->getKey(), 'statement_date' => $data['statement_date'],
                'opening_balance' => $last ? $this->decimal($last->ending_balance) : '0.00', 'ending_balance' => $this->decimal($data['ending_balance']),
                'created_by' => $userId,
            ]);
            // What the statement already explains is on the statement: tick it.
            $onStatement = $this->candidates($r, $bankAccount)->whereNotNull('statement_line_id')->pluck('id')->all();
            $this->tick($r, $onStatement);

            return $r;
        });
        $this->audit->record($this->current->id(), 'reconciliation_started', 'bank_account', $bankAccount->getKey(), null,
            ['statement_date' => $data['statement_date'], 'ending_balance' => $this->decimal($data['ending_balance'])], userId: $userId);

        return response()->json($this->workspace($reconciliation->refresh(), $bankAccount), 201);
    }

    public function show(Reconciliation $reconciliation): JsonResponse
    {
        $this->authorizeCompany('banking.view');

        return response()->json($this->workspace($reconciliation, Account::query()->findOrFail($reconciliation->account_id)));
    }

    /** Save the work in progress: the statement's date and ending balance, and which entries are ticked. */
    public function update(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->authorizeCompany('banking.reconcile');
        $data = $request->validate([
            'statement_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'ending_balance' => ['sometimes', 'decimal:0,2'],
            'cleared' => ['sometimes', 'array', 'max:5000'],
            'cleared.*' => ['uuid', 'distinct'],
        ]);
        $this->assertDraft($reconciliation);
        $account = Account::query()->findOrFail($reconciliation->account_id);

        DB::transaction(function () use ($reconciliation, $account, $data) {
            if (isset($data['statement_date']) || isset($data['ending_balance'])) {
                $reconciliation->statement_date = $data['statement_date'] ?? $reconciliation->statement_date;
                $reconciliation->ending_balance = isset($data['ending_balance']) ? $this->decimal($data['ending_balance']) : $reconciliation->ending_balance;
                $this->saving(fn () => $reconciliation->save(), 'statement_date');
                // An earlier statement date leaves out what is dated after it.
                $late = DB::table('reconciliation_lines as rl')->join('journal_lines as jl', fn ($j) => $j->on('jl.company_id', '=', 'rl.company_id')->on('jl.id', '=', 'rl.journal_line_id'))
                    ->where(['rl.company_id' => $this->current->id(), 'rl.reconciliation_id' => $reconciliation->getKey()])
                    ->where('jl.entry_date', '>', $reconciliation->statement_date)->pluck('rl.journal_line_id')->all();
                DB::table('reconciliation_lines')->where(['company_id' => $this->current->id(), 'reconciliation_id' => $reconciliation->getKey()])->whereIn('journal_line_id', $late)->delete();
            }
            if (array_key_exists('cleared', $data)) {
                $wanted = array_values(array_unique($data['cleared']));
                $open = $this->candidates($reconciliation, $account)->pluck('id')->flip();
                $unknown = array_filter($wanted, fn ($id) => ! isset($open[$id]));
                if ($unknown !== []) {
                    throw ValidationException::withMessages(['cleared' => ["Tick only entries on {$account->name} dated on or before {$reconciliation->statement_date} that no other reconciliation has cleared."]]);
                }
                DB::table('reconciliation_lines')->where(['company_id' => $this->current->id(), 'reconciliation_id' => $reconciliation->getKey()])
                    ->whereNotIn('journal_line_id', $wanted === [] ? ['00000000-0000-0000-0000-000000000000'] : $wanted)->delete();
                $this->tick($reconciliation, $wanted);
            }
        });

        return response()->json($this->workspace($reconciliation->refresh(), $account));
    }

    /** Finish: only at a zero difference. The report is saved and the account is locked through the statement date. */
    public function complete(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->authorizeCompany('banking.reconcile');
        $this->assertDraft($reconciliation);
        $account = Account::query()->findOrFail($reconciliation->account_id);
        $work = $this->workspace($reconciliation, $account);
        if ($work['difference'] !== '0.00') {
            throw ValidationException::withMessages(['difference' => ["The difference is {$work['difference']}: tick the entries the statement shows, or check its ending balance."]]);
        }

        $userId = $request->user()->getKey();
        $report = [
            'currency' => $work['account']['currency'], 'statement_date' => $reconciliation->statement_date,
            'opening' => $work['opening_balance'], 'ending' => $work['ending_balance'],
            'cleared_in' => $work['cleared_in'], 'cleared_out' => $work['cleared_out'],
            'cleared' => collect($work['lines'])->where('cleared', true)->map(fn ($l) => array_diff_key($l, ['cleared' => 1]))->values()->all(),
            'outstanding' => collect($work['lines'])->where('cleared', false)->map(fn ($l) => array_diff_key($l, ['cleared' => 1]))->values()->all(),
            'outstanding_total' => $work['outstanding_total'], 'book_balance' => $work['book_balance'],
        ];
        $reconciliation->status = 'completed';
        $reconciliation->report = $report;
        $reconciliation->completed_by = $userId;
        $reconciliation->completed_at = now();
        $this->saving(fn () => $reconciliation->save(), 'difference');
        $this->audit->record($this->current->id(), 'reconciliation_completed', 'bank_account', $account->getKey(), null,
            ['reconciliation' => $reconciliation->getKey(), 'statement_date' => $reconciliation->statement_date, 'ending_balance' => $work['ending_balance'],
                'cleared' => count($report['cleared']), 'outstanding' => count($report['outstanding'])], userId: $userId);

        return response()->json($this->workspace($reconciliation->refresh(), $account));
    }

    /** Undo the latest completed reconciliation of the account, with a reason: its entries are free and the period opens again. */
    public function undo(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->authorizeCompany('banking.reconcile');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        if ($reconciliation->status !== 'completed') {
            throw ValidationException::withMessages(['status' => [$reconciliation->status === 'draft' ? 'This reconciliation is still in progress: discard it instead.' : 'This reconciliation is already undone.']]);
        }
        $later = Reconciliation::query()->where(['account_id' => $reconciliation->account_id, 'status' => 'completed'])
            ->where('statement_date', '>', $reconciliation->statement_date)->orderBy('statement_date')->first();
        if ($later !== null) {
            throw ValidationException::withMessages(['status' => ["Undo the reconciliation to {$later->statement_date} first: the latest is undone first."]]);
        }
        if (Reconciliation::query()->where(['account_id' => $reconciliation->account_id, 'status' => 'draft'])->exists()) {
            throw ValidationException::withMessages(['status' => ['Discard the reconciliation in progress first: it starts from this one.']]);
        }

        $userId = $request->user()->getKey();
        $reconciliation->status = 'undone';
        $reconciliation->undo_reason = $data['reason'];
        $reconciliation->undone_by = $userId;
        $reconciliation->undone_at = now();
        $this->saving(fn () => $reconciliation->save(), 'status');
        $this->audit->record($this->current->id(), 'reconciliation_undone', 'bank_account', $reconciliation->account_id,
            ['reconciliation' => $reconciliation->getKey(), 'statement_date' => $reconciliation->statement_date, 'status' => 'completed'],
            ['status' => 'undone'], $data['reason'], userId: $userId);

        return response()->json($this->workspace($reconciliation->refresh(), Account::query()->findOrFail($reconciliation->account_id)));
    }

    /** Throw away a reconciliation in progress (and its ticks), saying why. */
    public function destroy(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->authorizeCompany('banking.reconcile');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->assertDraft($reconciliation);
        $before = $this->summary($reconciliation);
        $this->saving(fn () => $reconciliation->delete(), 'status');
        $this->audit->record($this->current->id(), 'reconciliation_discarded', 'bank_account', $reconciliation->account_id, $before, null, $data['reason'],
            userId: $request->user()->getKey());

        return response()->json(['discarded' => true]);
    }

    /**
     * The reconciliation as the screen shows it. For a draft: every entry on the account up to the statement date that no
     * other reconciliation has cleared, ticked or not, and the difference still to explain. For a completed or undone one:
     * the report saved when it was finished.
     *
     * @return array<string, mixed>
     */
    private function workspace(Reconciliation $r, Account $account): array
    {
        $head = [...$this->summary($r), 'account' => $this->accountOf($account), 'can' => ['reconcile' => $this->canReconcile()]];
        if ($r->status !== 'draft') {
            return [...$head, ...($r->report ?? []), 'lines' => null, 'undo_reason' => $r->undo_reason, 'undone_at' => $r->undone_at];
        }

        $currency = $this->currencyOf($account);
        $lines = $this->candidates($r, $account)->map(fn ($l) => [
            'journal_line_id' => $l->id, 'date' => $l->entry_date, 'entry' => $l->entry, 'source_type' => $l->source_type,
            'memo' => $l->memo ?? $l->description, 'contact' => $l->contact, 'amount' => $this->amountOn($account, $l),
            'on_statement' => $l->statement_line_id !== null, 'cleared' => (bool) $l->cleared,
        ])->values();
        $sum = fn (Collection $c) => $c->reduce(fn (Money $t, array $l) => $t->plus(Money::of($l['amount'], $currency)), Money::zero($currency));
        $cleared = $lines->where('cleared', true);
        $in = $sum($cleared->filter(fn ($l) => ! str_starts_with($l['amount'], '-')));
        $out = $sum($cleared->filter(fn ($l) => str_starts_with($l['amount'], '-')));
        $opening = Money::of($this->decimal($r->opening_balance), $currency);
        $ending = Money::of($this->decimal($r->ending_balance), $currency);
        $clearedBalance = $opening->plus($in)->plus($out);
        $outstanding = $sum($lines->where('cleared', false));

        return [...$head,
            'lines' => $lines->all(),
            'cleared_in' => $in->toDecimalString(), 'cleared_out' => $out->negated()->toDecimalString(),
            'cleared_balance' => $clearedBalance->toDecimalString(), 'difference' => $ending->minus($clearedBalance)->toDecimalString(),
            'outstanding_total' => $outstanding->toDecimalString(), 'book_balance' => $ending->plus($outstanding)->toDecimalString(),
        ];
    }

    /** Entries on the account dated up to the statement date, not cleared by another reconciliation; this one's ticks flagged. */
    private function candidates(Reconciliation $r, Account $account): Collection
    {
        $companyId = $this->current->id();

        return DB::table('journal_lines as l')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->leftJoin('reconciliation_lines as mine', fn ($j) => $j->on('mine.company_id', '=', 'l.company_id')->on('mine.journal_line_id', '=', 'l.id')
                ->where('mine.reconciliation_id', '=', $r->getKey()))
            ->leftJoin('bank_transactions as bt', fn ($j) => $j->on('bt.company_id', '=', 'l.company_id')->on('bt.journal_line_id', '=', 'l.id'))
            ->where(['l.company_id' => $companyId, 'l.account_id' => $account->getKey()])
            ->where('l.entry_date', '<=', $r->statement_date)
            ->whereNotExists(fn ($q) => $q->from('reconciliation_lines as other')->whereColumn('other.company_id', 'l.company_id')
                ->whereColumn('other.journal_line_id', 'l.id')->where('other.reconciliation_id', '<>', $r->getKey()))
            ->orderBy('l.entry_date')->orderBy('e.number')->orderBy('l.line_no')->limit(5000)
            ->get(['l.id', 'l.entry_date', 'l.debit', 'l.credit', 'l.amount_txn', 'l.description', 'e.number as entry', 'e.memo', 'e.source_type',
                'c.name as contact', 'mine.journal_line_id as cleared', 'bt.id as statement_line_id']);
    }

    /** @param list<string> $ids */
    private function tick(Reconciliation $r, array $ids): void
    {
        $have = DB::table('reconciliation_lines')->where(['company_id' => $this->current->id(), 'reconciliation_id' => $r->getKey()])->pluck('journal_line_id')->flip();
        $rows = collect($ids)->reject(fn ($id) => isset($have[$id]))
            ->map(fn ($id) => ['company_id' => $this->current->id(), 'reconciliation_id' => $r->getKey(), 'journal_line_id' => $id])->values()->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->saving(fn () => DB::table('reconciliation_lines')->insert($chunk), 'cleared');
        }
    }

    /** A rule the database checks (028) answered as a 422 on the field it concerns, not a server error. */
    private function saving(callable $write, string $field): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23505'], true)) {
                $message = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The reconciliation was refused.';
                throw ValidationException::withMessages([$field => [$e->getCode() === '23505' ? 'Someone else changed this reconciliation a moment ago.' : $message]]);
            }
            throw $e;
        }
    }

    private function assertDraft(Reconciliation $r): void
    {
        if ($r->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ["This reconciliation is {$r->status}: it does not change."]]);
        }
    }

    /** @return array<string, mixed> */
    private function summary(Reconciliation $r): array
    {
        return [
            'id' => $r->getKey(), 'account_id' => $r->account_id, 'status' => $r->status, 'statement_date' => $r->statement_date,
            'opening_balance' => $this->decimal($r->opening_balance), 'ending_balance' => $this->decimal($r->ending_balance),
            'completed_at' => $r->completed_at, 'undone_at' => $r->undone_at, 'undo_reason' => $r->undo_reason,
        ];
    }

    /** @return array<string, string> */
    private function accountOf(Account $a): array
    {
        return ['id' => $a->getKey(), 'code' => $a->code, 'name' => $a->name, 'currency' => $this->currencyOf($a)];
    }

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
        return (string) BigDecimal::of($value)->toScale(2);
    }

    private function canReconcile(): bool
    {
        return (bool) $this->current->membership()?->can('banking.reconcile');
    }
}
