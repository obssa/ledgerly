<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Support\CountryPack;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 6, the chart of accounts: every account with its balance, in code order under its parent. Accountants add
 * accounts, rename and re-code them, group them under a parent and archive the empty ones. System accounts (the ones the
 * posting code uses) stay; bank, wallet and cash accounts are opened under Banking; receivables, payables and stock are
 * kept by their documents. The database checks these rules again (026, 031).
 */
final class ChartController extends Controller
{
    /** Kinds an account opened here can have, by type. Bank, cash and wallets open under Banking. */
    public const KINDS = [
        'asset' => ['fixed_asset', 'contra_asset', 'prepayment', 'deposit', 'tax_receivable'],
        'liability' => ['accrual', 'loan', 'deferred_income', 'tax_payable', 'payroll'],
        'equity' => ['capital', 'drawings', 'reserve'],
        'income' => ['sales', 'other_income', 'contra_income'],
        'expense' => ['operating', 'cogs', 'depreciation', 'finance_cost', 'income_tax'],
    ];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $archived = $request->boolean('include_archived');
        $accounts = Account::query()->when(! $archived, fn ($q) => $q->where('is_active', true))->orderBy('code')->get();
        $balances = DB::table('account_balances')->where('company_id', $this->current->id())->groupBy('account_id')
            ->select('account_id')->selectRaw('sum(debit - credit)::numeric(20,2)::text AS balance')->pluck('balance', 'account_id');
        $used = DB::table('account_balances')->where('company_id', $this->current->id())->distinct()->pluck('account_id')->flip();
        // An account kept in another currency: its balance in that currency too (QuickBooks: Bank balance beside the
        // balance in the company's currency).
        $base = $this->current->get()->base_currency;
        $foreignIds = $accounts->filter(fn ($a) => $a->currency !== null && $a->currency !== $base)->map->getKey()->values()->all();
        $foreign = $foreignIds === [] ? collect() : DB::table('journal_lines')->where('company_id', $this->current->id())->whereIn('account_id', $foreignIds)
            ->groupBy('account_id')->select('account_id')->selectRaw('sum(amount_txn)::numeric(20,2)::text AS balance')->pluck('balance', 'account_id');

        return response()->json([
            'data' => $this->ordered($accounts)->map(fn (array $row) => [
                ...$this->present($row['account'], $balances[$row['account']->getKey()] ?? '0.00', isset($used[$row['account']->getKey()])),
                ...(in_array($row['account']->getKey(), $foreignIds, true) ? ['foreign_balance' => $foreign[$row['account']->getKey()] ?? '0.00'] : []),
                'depth' => $row['depth'],
            ])->values(),
            'kinds' => self::KINDS,
            'can' => ['manage' => (bool) $this->current->membership()?->can('accounting.chart')],
        ]);
    }

    /**
     * The industry templates (Module 1), each account marked as one the chart has already (the same code and name, or
     * the name on another code), one whose code another account uses, or one to add. For a company that changes its
     * line of business after setup, or started without one.
     */
    public function industries(): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $industry = (string) DB::table('companies')->where('id', $this->current->id())->value('industry');
        $chart = $this->chartIndex();
        $data = [];
        foreach (CountryPack::industries() as $key => $t) {
            $accounts = array_map(fn (array $a) => [...array_intersect_key($a, array_flip(['code', 'name', 'type', 'subtype'])), 'status' => $this->templateStatus($a, $chart)], $t['accounts']);
            $data[] = ['key' => $key, 'name' => $t['name'], 'accounts' => $accounts,
                'to_add' => count(array_filter($accounts, fn ($a) => $a['status'] === 'add'))];
        }

        return response()->json(['industry' => $industry === '' ? null : $industry, 'data' => $data,
            'can' => ['manage' => (bool) $this->current->membership()?->can('accounting.chart')]]);
    }

    /** Adds the template's accounts the chart does not have yet; the rest are left as they are. */
    public function addIndustryAccounts(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.chart');
        $data = $request->validate(['industry' => ['required', 'string', Rule::in(array_keys(CountryPack::industries()))]]);
        $template = CountryPack::industries()[$data['industry']];

        $added = $this->saving(function () use ($template) {
            $chart = $this->chartIndex();
            $added = [];
            foreach ($template['accounts'] as $a) {
                if ($this->templateStatus($a, $chart) !== 'add') {
                    continue;
                }
                Account::query()->create(['code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'subtype' => $a['subtype'] ?? null,
                    'bank_details' => (object) []]);
                $added[] = ['code' => $a['code'], 'name' => $a['name']];
            }

            return $added;
        });
        $this->audit->record($this->current->id(), 'industry_accounts_added', 'company', $this->current->id(), null,
            ['industry' => $data['industry'], 'accounts' => $added], userId: $request->user()->getKey());

        return response()->json(['industry' => $data['industry'], 'added' => $added], $added === [] ? 200 : 201);
    }

    /** @return array{codes: array<string, string>, names: array<string, true>} the chart's codes (to names) and names, any case */
    private function chartIndex(): array
    {
        $codes = Account::query()->pluck('name', 'code')->all();

        return ['codes' => $codes, 'names' => array_fill_keys(array_map(fn ($n) => mb_strtolower(trim((string) $n)), $codes), true)];
    }

    /** have: the chart has it (by code and name, or by name); taken: another account has its code; add: neither. */
    private function templateStatus(array $a, array $chart): string
    {
        $name = mb_strtolower(trim($a['name']));
        if (isset($chart['names'][$name])) {
            return 'have';
        }

        return isset($chart['codes'][$a['code']]) ? 'taken' : 'add';
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.chart');
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9A-Za-z.-]{1,20}$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(self::KINDS))],
            'kind' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'uuid'],
        ]);
        $this->assertKind($data['type'], $data['kind'] ?? null);
        if (Account::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ["Code {$data['code']} is already used in this chart."]]);
        }
        $parent = $this->parent($data['parent_id'] ?? null, $data['type']);

        $account = $this->saving(fn () => Account::query()->create([
            'code' => $data['code'], 'name' => trim($data['name']), 'type' => $data['type'], 'subtype' => $data['kind'] ?? null,
            'parent_id' => $parent?->getKey(), 'bank_details' => (object) [],
        ]));
        $this->audit->record($this->current->id(), 'created', 'account', $account->getKey(), null,
            ['code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'kind' => $account->subtype, 'parent' => $parent?->code],
            userId: $request->user()->getKey());

        return response()->json($this->present($account->refresh(), '0.00', false), 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorizeCompany('accounting.chart');
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'regex:/^[0-9A-Za-z.-]{1,20}$/'],
            'name' => ['sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', 'nullable', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $money = in_array($account->subtype, BankAccountController::KINDS, true) || $account->system_key === 'undeposited_funds';
        if ($money && array_key_exists('kind', $data)) {
            throw ValidationException::withMessages(['kind' => ["{$account->name} is a bank, wallet or cash account: manage it under Banking."]]);
        }
        if (array_key_exists('kind', $data)) {
            if ($account->system_key !== null) {
                throw ValidationException::withMessages(['kind' => ["{$account->name} is a system account: its kind stays."]]);
            }
            $this->assertKind($account->type, $data['kind']);
        }
        if (isset($data['code']) && $data['code'] !== $account->code && Account::query()->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ["Code {$data['code']} is already used in this chart."]]);
        }
        if (array_key_exists('parent_id', $data)) {
            $this->parent($data['parent_id'], $account->type, $account);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $account->system_key !== null) {
            throw ValidationException::withMessages(['is_active' => ["{$account->name} is a system account: it stays active."]]);
        }

        $before = ['code' => $account->code, 'name' => $account->name, 'kind' => $account->subtype, 'parent_id' => $account->parent_id, 'is_active' => $account->is_active];
        $account->fill(array_intersect_key([...$data, ...(array_key_exists('kind', $data) ? ['subtype' => $data['kind']] : [])],
            array_flip(['code', 'name', 'subtype', 'parent_id', 'is_active'])));
        if (isset($data['name'])) {
            $account->name = trim($data['name']);
        }
        $after = ['code' => $account->code, 'name' => $account->name, 'kind' => $account->subtype, 'parent_id' => $account->parent_id, 'is_active' => $account->is_active];
        $changes = AuditLogger::diff($before, $after);
        if ($changes !== []) {
            $this->saving(fn () => $account->save());
            $this->audit->record($this->current->id(), array_key_exists('is_active', $changes) ? ($account->is_active ? 'restored' : 'archived') : 'updated',
                'account', $account->getKey(), array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes),
                userId: $request->user()->getKey());
        }
        $rows = DB::table('account_balances')->where(['company_id' => $this->current->id(), 'account_id' => $account->getKey()]);
        $balance = (clone $rows)->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS balance')->value('balance');

        return response()->json($this->present($account->refresh(), (string) $balance, $rows->exists()));
    }

    private function assertKind(string $type, ?string $kind): void
    {
        if ($kind !== null && ! in_array($kind, self::KINDS[$type] ?? [], true)) {
            throw ValidationException::withMessages(['kind' => [in_array($kind, BankAccountController::KINDS, true)
                ? 'Open bank, wallet and cash accounts under Banking.'
                : "Choose a kind of {$type} account: " . implode(', ', str_replace('_', ' ', self::KINDS[$type] ?? [])) . '.']]);
        }
    }

    /** The parent named, loaded through the company scope: an active account of the same type, not the account itself. */
    private function parent(?string $id, string $type, ?Account $self = null): ?Account
    {
        if ($id === null) {
            return null;
        }
        $parent = Account::query()->where('is_active', true)->find($id);
        if ($parent === null || $parent->type !== $type || $parent->getKey() === $self?->getKey()) {
            throw ValidationException::withMessages(['parent_id' => ["Choose an active {$type} account of this chart to sit under."]]);
        }

        return $parent;
    }

    /** A chart rule the database checks (031), answered as a 422 instead of a server error. */
    private function saving(callable $write): mixed
    {
        try {
            return DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23505'], true)) {
                $message = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The chart refused this change.';
                throw ValidationException::withMessages(['account' => [$e->getCode() === '23505' ? 'That code is already used in this chart.' : $message]]);
            }
            throw $e;
        }
    }

    /**
     * Accounts in code order, each under its parent (depth 0 at the top). An account whose parent is not listed (archived)
     * shows at the top.
     *
     * @param Collection<int, Account> $accounts
     * @return Collection<int, array{account: Account, depth: int}>
     */
    private function ordered(Collection $accounts): Collection
    {
        $ids = $accounts->pluck('id')->flip();
        $children = $accounts->groupBy(fn (Account $a) => $a->parent_id !== null && isset($ids[$a->parent_id]) ? $a->parent_id : '');
        $out = collect();
        $walk = function (string $parent, int $depth) use (&$walk, $children, $out) {
            foreach ($children[$parent] ?? [] as $a) {
                $out->push(['account' => $a, 'depth' => $depth]);
                if ($depth < 4) {
                    $walk($a->getKey(), $depth + 1);
                }
            }
        };
        $walk('', 0);

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(Account $a, string $balance, bool $used): array
    {
        return [
            'id' => $a->getKey(), 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'kind' => $a->subtype, 'system_key' => $a->system_key,
            'parent_id' => $a->parent_id, 'currency' => $a->currency, 'is_active' => (bool) $a->is_active, 'balance' => $balance, 'has_entries' => $used,
            'is_system' => $a->system_key !== null, 'is_money' => in_array($a->subtype, BankAccountController::KINDS, true) || $a->system_key === 'undeposited_funds',
        ];
    }
}
