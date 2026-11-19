<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\Account;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5: bank rules (076). How a kind of statement line is categorised, kept by those who review statements
 * (banking.review): the words to look for, the account it is for, its direction and amount range, and the account,
 * contact and memo it goes to. A rule posts nothing; applying the rules categorises the lines they fit. Every change
 * is audited. Every query is the company's own under row-level security.
 */
final class BankRuleController extends Controller
{
    private const FIELDS = ['name', 'bank_account_id', 'direction', 'contains', 'amount_min', 'amount_max', 'target_account_id', 'contact_id', 'memo', 'priority', 'is_active'];

    /** Accounts a statement line is never categorised to (StatementController::NOT_FOR_CATEGORISING). */
    private const NOT_FOR_CATEGORISING = ['receivable', 'payable', 'inventory', 'opening_balance', 'retained_earnings'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('banking.view');

        return response()->json([
            'data' => DB::table('bank_rules as r')->join('accounts as a', 'a.id', '=', 'r.target_account_id')
                ->leftJoin('accounts as b', 'b.id', '=', 'r.bank_account_id')->leftJoin('contacts as c', 'c.id', '=', 'r.contact_id')
                ->orderBy('r.priority')->orderBy('r.created_at')
                ->get(['r.id', 'r.name', 'r.bank_account_id', 'b.name as bank_account', 'r.direction', 'r.contains', 'r.amount_min', 'r.amount_max',
                    'r.target_account_id', 'a.code as target_code', 'a.name as target_name', 'r.contact_id', 'c.name as contact_name', 'r.memo',
                    'r.priority', 'r.is_active']),
            'can' => ['manage' => (bool) $this->current->membership()?->can('banking.review')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $data = $this->validated($request, true);
        $id = (string) Str::uuid7();
        DB::table('bank_rules')->insert(['id' => $id, 'company_id' => $this->current->id(), ...$data, 'created_by' => $request->user()->getKey()]);
        $this->audit->record($this->current->id(), 'bank_rule_created', 'bank_rule', $id, null, $data, userId: $request->user()->getKey());

        return response()->json($this->find($id), 201);
    }

    public function update(Request $request, string $bankRule): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $before = (array) $this->find($bankRule);
        $data = $this->validated($request, false, $before);
        if ($data !== []) {   // nothing given: nothing changes
            DB::table('bank_rules')->where('id', $bankRule)->update($data);
        }
        $after = (array) $this->find($bankRule);
        $changed = array_keys(array_filter($data, fn ($v, $k) => (string) ($before[$k] ?? '') !== (string) ($after[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->audit->record($this->current->id(), 'bank_rule_updated', 'bank_rule', $bankRule, array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)), userId: $request->user()->getKey());
        }

        return response()->json($after);
    }

    public function destroy(Request $request, string $bankRule): JsonResponse
    {
        $this->authorizeCompany('banking.review');
        $before = (array) $this->find($bankRule);
        DB::table('bank_rules')->where('id', $bankRule)->delete();
        $this->audit->record($this->current->id(), 'bank_rule_deleted', 'bank_rule', $bankRule, array_intersect_key($before, array_flip(self::FIELDS)), null,
            userId: $request->user()->getKey());

        return response()->json(['deleted' => true]);
    }

    /** @param array<string, mixed> $before @return array<string, mixed> */
    private function validated(Request $request, bool $new, array $before = []): array
    {
        $req = $new ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'contains' => [$req, 'string', 'max:120'],
            'target_account_id' => [$req, 'uuid', Rule::exists('accounts', 'id')->where('is_active', true)],
            'bank_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->whereIn('subtype', ['bank', 'cash', 'mobile_money']))],
            'direction' => ['sometimes', Rule::in(['in', 'out', 'any'])],
            'amount_min' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'amount_max' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'contact_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('contacts', 'id')],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => [$new ? 'prohibited' : 'sometimes', 'boolean'],
        ], ['target_account_id.exists' => 'Choose an active account of this company.', 'bank_account_id.exists' => 'Choose a bank, cash or mobile-money account of this company.']);
        foreach (['name', 'contains'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = trim($data[$k]);
                if ($data[$k] === '') {
                    throw ValidationException::withMessages([$k => ['This cannot be empty.']]);
                }
            }
        }
        $min = array_key_exists('amount_min', $data) ? $data['amount_min'] : ($before['amount_min'] ?? null);
        $max = array_key_exists('amount_max', $data) ? $data['amount_max'] : ($before['amount_max'] ?? null);
        if ($min !== null && $max !== null && bccomp((string) $min, (string) $max, 2) > 0) {
            throw ValidationException::withMessages(['amount_max' => ['The largest amount is at least the smallest.']]);
        }
        if (isset($data['target_account_id'])) {
            // Loaded through the company-scoped model (CLAUDE.md rule 4).
            $target = Account::query()->findOrFail($data['target_account_id']);
            if (in_array($target->subtype, self::NOT_FOR_CATEGORISING, true)) {
                throw ValidationException::withMessages(['target_account_id' => ["{$target->name} is kept by its own documents: a statement line is matched to them, not categorised."]]);
            }
            $bank = array_key_exists('bank_account_id', $data) ? $data['bank_account_id'] : ($before['bank_account_id'] ?? null);
            if ($bank !== null && $bank === $data['target_account_id']) {
                throw ValidationException::withMessages(['target_account_id' => ['A rule categorises to another account than the one it is for.']]);
            }
        }

        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private function find(string $id): object
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        return DB::table('bank_rules')->where('id', $id)->first([...self::FIELDS, 'id', 'created_at', 'updated_at']) ?? abort(404);
    }
}
