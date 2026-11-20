<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 1 (094): custom tags, put on whole transactions (invoices, bills, expenses, journal entries), several to a
 * transaction, and the profit by tag report. Tags are named and turned off by whoever manages the dimensions; a
 * transaction is tagged by whoever can write that kind of transaction.
 */
final class TagController extends Controller
{
    /** Per kind of transaction: who sees its tags, who puts them on. */
    private const PERMISSIONS = [
        'invoice' => ['invoices.view', 'invoices.create'], 'bill' => ['bills.view', 'bills.create'],
        'expense' => ['expenses.view', 'expenses.create'], 'journal' => ['accounting.view', 'journals.create'],
    ];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $used = DB::table('transaction_tags')->where('company_id', $this->current->id())->groupBy('tag_id')->select('tag_id')
            ->selectRaw('count(*) AS n')->pluck('n', 'tag_id');

        return response()->json([
            'data' => DB::table('tags')->where('company_id', $this->current->id())->orderByDesc('is_active')->orderBy('name')->limit(500)
                ->get(['id', 'name', 'is_active'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'is_active' => (bool) $t->is_active, 'used' => (int) ($used[$t->id] ?? 0)])->values(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('accounting.dimensions')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.dimensions');
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);
        $name = $this->name($data['name']);
        $id = (string) Str::uuid7();
        DB::table('tags')->insert(['id' => $id, 'company_id' => $this->current->id(), 'name' => $name]);
        $this->audit->record($this->current->id(), 'created', 'tag', $id, null, ['name' => $name], userId: $request->user()->getKey());

        return response()->json(['id' => $id, 'name' => $name, 'is_active' => true, 'used' => 0], 201);
    }

    public function update(Request $request, string $tag): JsonResponse
    {
        $this->authorizeCompany('accounting.dimensions');
        $t = $this->tag($tag);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:40'], 'is_active' => ['sometimes', 'boolean']]);
        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = $this->name($data['name'], $t->id);
        }
        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
        }
        if ($changes !== []) {
            DB::table('tags')->where(['company_id' => $this->current->id(), 'id' => $t->id])->update([...$changes, 'updated_at' => now()]);
            $this->audit->record($this->current->id(), 'updated', 'tag', $t->id, ['name' => $t->name, 'is_active' => (bool) $t->is_active], $changes,
                userId: $request->user()->getKey());
        }
        $t = $this->tag($tag);

        return response()->json(['id' => $t->id, 'name' => $t->name, 'is_active' => (bool) $t->is_active,
            'used' => DB::table('transaction_tags')->where(['company_id' => $this->current->id(), 'tag_id' => $t->id])->count()]);
    }

    /** The tags on an invoice, bill or expense, and the active tags it can have. */
    public function forDocument(Request $request): JsonResponse
    {
        [$kind, $document] = $this->document($request);
        $this->authorizeCompany(self::PERMISSIONS[$kind][0]);

        return $this->listed(['document_id' => $document->getKey()], $kind);
    }

    /** Puts the tags given on an invoice, bill or expense (the ones it had and not given are taken off). */
    public function setDocument(Request $request): JsonResponse
    {
        [$kind, $document] = $this->document($request);
        $this->authorizeCompany(self::PERMISSIONS[$kind][1]);

        return $this->set($request, ['document_id' => $document->getKey()], $kind, (string) $document->getKey());
    }

    public function forEntry(JournalEntry $entry): JsonResponse
    {
        $this->authorizeCompany(self::PERMISSIONS['journal'][0]);

        return $this->listed(['journal_entry_id' => $entry->getKey()], 'journal');
    }

    public function setEntry(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->authorizeCompany(self::PERMISSIONS['journal'][1]);

        return $this->set($request, ['journal_entry_id' => $entry->getKey()], 'journal', (string) $entry->getKey());
    }

    /**
     * Profit by tag for a period: for each tag, the income and expenses of the transactions it is on (a tagged
     * document's own entries, including a corrected one, a tagged journal entry, and the reversals of either), by
     * account. A transaction with two tags counts under each. The period defaults to the year so far.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $q['to'] ??= $this->current->today();
        $q['from'] ??= substr($q['to'], 0, 4) . '-01-01';   // this year so far, unless asked
        $companyId = $this->current->id();
        $rows = [];
        foreach (DB::table('tags')->where('company_id', $companyId)->orderBy('name')->limit(500)->get(['id', 'name', 'is_active']) as $t) {
            $accounts = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.company_id', $companyId)->whereIn('a.type', ['income', 'expense'])->whereBetween('l.entry_date', [$q['from'], $q['to']])
                ->where(fn ($w) => $w->whereIn('l.journal_entry_id', $this->entriesOf($companyId, $t->id))
                    ->orWhereIn('l.journal_entry_id', DB::table('journal_entries as r')->where(['r.company_id' => $companyId, 'r.source_type' => 'reversal'])
                        ->whereIn('r.source_id', $this->entriesOf($companyId, $t->id))->select('r.id')))
                ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.type', 'desc')->orderBy('a.code')->select('a.id', 'a.code', 'a.name', 'a.type')
                ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END)::numeric(20,2)::text AS amount")->get();
            $income = $accounts->where('type', 'income')->reduce(fn (string $s, $a) => bcadd($s, $a->amount, 2), '0.00');
            $expenses = $accounts->where('type', 'expense')->reduce(fn (string $s, $a) => bcadd($s, $a->amount, 2), '0.00');
            $used = DB::table('transaction_tags')->where(['company_id' => $companyId, 'tag_id' => $t->id])->count();
            if ($used === 0 && ! $t->is_active) {
                continue;
            }
            $rows[] = ['id' => $t->id, 'name' => $t->name, 'is_active' => (bool) $t->is_active, 'transactions' => $used,
                'income' => $income, 'expenses' => $expenses, 'profit' => bcsub($income, $expenses, 2),
                'accounts' => $accounts->map(fn ($a) => ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'amount' => $a->amount])->values()];
        }

        return response()->json(['from' => $q['from'], 'to' => $q['to'], 'data' => $rows]);
    }

    /** The journal entries a tag is on: its documents' own entries and the entries tagged themselves. */
    private function entriesOf(string $companyId, string $tagId): Builder
    {
        $tagged = fn (string $column) => DB::table('transaction_tags')->where(['company_id' => $companyId, 'tag_id' => $tagId])->whereNotNull($column)->select($column);

        return DB::table('journal_entries as e')->where('e.company_id', $companyId)
            ->where(fn ($w) => $w->whereIn('e.source_id', $tagged('document_id'))->orWhereIn('e.id', $tagged('journal_entry_id')))->select('e.id');
    }

    /** @param array<string, string> $on */
    private function listed(array $on, string $kind): JsonResponse
    {
        return response()->json([
            'data' => $this->current($on),
            'tags' => DB::table('tags')->where(['company_id' => $this->current->id(), 'is_active' => true])->orderBy('name')->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'can' => ['tag' => (bool) $this->current->membership()?->can(self::PERMISSIONS[$kind][1])],
        ]);
    }

    /** @param array<string, string> $on */
    private function set(Request $request, array $on, string $kind, string $subjectId): JsonResponse
    {
        $data = $request->validate(['tag_ids' => ['present', 'array', 'max:20'], 'tag_ids.*' => ['uuid', 'distinct']]);
        $companyId = $this->current->id();
        $now = collect($this->current($on))->pluck('id')->all();
        $wanted = array_values(array_unique($data['tag_ids']));
        $adding = array_values(array_diff($wanted, $now));
        if ($adding !== [] && DB::table('tags')->where(['company_id' => $companyId, 'is_active' => true])->whereIn('id', $adding)->count() !== count($adding)) {
            throw ValidationException::withMessages(['tag_ids' => ['Choose from the tags that are on.']]);
        }
        DB::transaction(function () use ($companyId, $on, $now, $wanted, $adding, $request) {
            $off = array_values(array_diff($now, $wanted));
            if ($off !== []) {
                DB::table('transaction_tags')->where(['company_id' => $companyId, ...$on])->whereIn('tag_id', $off)->delete();
            }
            DB::table('transaction_tags')->insert(array_map(fn (string $tag) => ['id' => (string) Str::uuid7(), 'company_id' => $companyId, 'tag_id' => $tag, ...$on,
                'created_by' => $request->user()->getKey()], $adding));
        });
        $after = $this->current($on);
        if (array_values(array_diff($now, $wanted)) !== [] || $adding !== []) {
            $this->audit->record($companyId, 'tags_set', $kind === 'journal' ? 'journal_entry' : 'document', $subjectId, ['tags' => $now],
                ['tags' => array_column($after, 'name')], userId: $request->user()->getKey());
        }

        return $this->listed($on, $kind);
    }

    /** @param array<string, string> $on @return list<array{id: string, name: string}> */
    private function current(array $on): array
    {
        return DB::table('transaction_tags as x')->join('tags as t', 't.id', '=', 'x.tag_id')->where(['x.company_id' => $this->current->id(), ...array_combine(
            array_map(fn ($k) => "x.{$k}", array_keys($on)), array_values($on))])->orderBy('t.name')->get(['t.id', 't.name'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all();
    }

    /** @return array{0: string, 1: Document} the kind of transaction the route is for, and the document (the company's, of that kind) */
    private function document(Request $request): array
    {
        $kind = (string) $request->route('kind');
        $document = $request->route($kind);
        if (! $document instanceof Document) {
            abort(404);
        }

        return [$kind, $document];
    }

    /** A name not yet used by another of the company's tags, in any case. */
    private function name(string $name, ?string $except = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Give the tag a name.']]);
        }
        $taken = DB::table('tags')->where('company_id', $this->current->id())->when($except, fn ($q, $v) => $q->where('id', '<>', $v))
            ->get(['name'])->contains(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name));
        if ($taken) {
            throw ValidationException::withMessages(['name' => ["There is already a tag called {$name}."]]);
        }

        return $name;
    }

    private function tag(string $id): object
    {
        return DB::table('tags')->where(['company_id' => $this->current->id(), 'id' => $id])->first(['id', 'name', 'is_active']) ?? abort(404);
    }
}
