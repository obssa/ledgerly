<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Currency;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 5, bank and cash accounts: every place the company keeps money (banks, mobile-money wallets, cash boxes), each a
 * ledger account of its own with its currency. Listed with balances; opened with the next free code in the chart's range;
 * renamed, given bank details (audited as such) or archived once empty. Its register is every entry on it.
 */
final class BankAccountController extends Controller
{
    public const KINDS = ['bank', 'mobile_money', 'cash'];

    /** Chart ranges new accounts take their code from (IFRS for SMEs chart: 1000 cash, 1100 bank, 1150 mobile money). */
    private const RANGES = ['cash' => [1000, 1049], 'bank' => [1100, 1149], 'mobile_money' => [1150, 1199]];

    private const DETAILS = ['bank_name', 'account_number', 'branch', 'provider', 'wallet_number'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $archived = $request->boolean('include_archived');
        $accounts = Account::query()->where(fn ($q) => $q->whereIn('subtype', self::KINDS)->orWhere('system_key', 'undeposited_funds'))
            ->when(! $archived, fn ($q) => $q->where('is_active', true))->orderBy('code')->get();
        $balances = $this->balances($accounts->pluck('id')->all());

        return response()->json([
            'data' => $accounts->map(fn (Account $a) => $this->present($a, $balances[$a->getKey()] ?? null)),
            'can' => $this->can(),
        ]);
    }

    public function show(Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');

        return response()->json([...$this->present($bankAccount, $this->balances([$bankAccount->getKey()])[$bankAccount->getKey()] ?? null), 'can' => $this->can()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('banking.manage');
        $company = $this->current->get();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'currency' => ['nullable', Rule::in(Currency::all())],
            'float_amount' => ['nullable', 'decimal:0,2', 'gt:0'],
            ...$this->detailRules(),
        ]);
        if (isset($data['float_amount']) && $data['kind'] !== 'cash') {
            throw ValidationException::withMessages(['float_amount' => ['Only a cash box or petty cash account keeps a float.']]);
        }
        $currency = $data['currency'] ?? $company->base_currency;
        if ($currency !== $company->base_currency
            && ! DB::table('company_currencies')->where(['company_id' => $company->getKey(), 'currency' => $currency, 'is_enabled' => true])->exists()) {
            throw ValidationException::withMessages(['currency' => ["Turn on {$currency} in company settings first."]]);
        }

        [$low, $high] = self::RANGES[$data['kind']];
        $used = Account::query()->pluck('code')->filter(fn ($c) => ctype_digit((string) $c))->map(fn ($c) => (int) $c)->all();
        $code = null;
        for ($n = $low; $n <= $high; $n++) {
            if (! in_array($n, $used, true)) {
                $code = (string) $n;
                break;
            }
        }
        if ($code === null) {
            throw ValidationException::withMessages(['kind' => ["The chart has no free code left between {$low} and {$high}."]]);
        }

        $account = Account::query()->create([
            'code' => $code, 'name' => $data['name'], 'type' => 'asset', 'subtype' => $data['kind'],
            'currency' => $currency === $company->base_currency ? null : $currency,
            'bank_details' => $this->details($data) ?: (object) [],   // an empty object, never a list (026)
            'float_amount' => $data['float_amount'] ?? null,
        ]);
        $this->audit->record($company->getKey(), 'created', 'bank_account', $account->getKey(), null,
            ['code' => $code, 'name' => $data['name'], 'kind' => $data['kind'], 'currency' => $currency, 'bank_details' => $this->details($data)],
            userId: $request->user()->getKey());

        return response()->json($this->present($account->refresh(), null), 201);
    }

    public function update(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'float_amount' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            ...$this->detailRules(),
        ]);
        if (array_key_exists('float_amount', $data) && $data['float_amount'] !== null && $bankAccount->subtype !== 'cash') {
            throw ValidationException::withMessages(['float_amount' => ['Only a cash box or petty cash account keeps a float.']]);
        }
        $before = ['name' => $bankAccount->name, 'is_active' => $bankAccount->is_active, 'bank_details' => $bankAccount->bank_details ?? [], 'float_amount' => $bankAccount->float_amount];
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $balance = $this->balances([$bankAccount->getKey()])[$bankAccount->getKey()] ?? null;
            if ($balance !== null && $balance->base !== '0.00') {
                throw ValidationException::withMessages(['is_active' => ["{$bankAccount->name} still holds {$balance->base}: move it out before archiving."]]);
            }
        }
        $bankAccount->fill(array_intersect_key($data, array_flip(['name', 'is_active', 'float_amount'])));
        if (array_intersect_key($data, array_flip(array_map(fn ($k) => "bank_details.{$k}", self::DETAILS))) !== [] || array_key_exists('bank_details', $data)) {
            $bankAccount->bank_details = $this->details($data) ?: (object) [];
        }
        $after = ['name' => $bankAccount->name, 'is_active' => $bankAccount->is_active, 'bank_details' => $bankAccount->bank_details ?? [], 'float_amount' => $bankAccount->float_amount];
        $changes = AuditLogger::diff($before, $after);
        if ($changes !== []) {
            $bankAccount->save();
            $this->audit->record($this->current->id(), array_key_exists('bank_details', $changes) ? 'bank_details_changed' : 'updated', 'bank_account', $bankAccount->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes), userId: $request->user()->getKey());
        }

        return response()->json($this->present($bankAccount->refresh(), $this->balances([$bankAccount->getKey()])[$bankAccount->getKey()] ?? null));
    }

    /** Every entry on the account in a period (default: the last 90 days), with the balance before it and a running balance. */
    public function register(Request $request, Account $bankAccount): JsonResponse
    {
        $this->authorizeCompany('banking.view');
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = $q['to'] ?? $this->current->today();
        $from = $q['from'] ?? now($this->current->get()->timezone ?: 'UTC')->subDays(90)->format('Y-m-d');
        $company = $this->current->get();
        $base = $company->base_currency;
        $foreign = $bankAccount->currency !== null && $bankAccount->currency !== $base;

        $opening = DB::table('journal_lines')->where(['company_id' => $company->getKey(), 'account_id' => $bankAccount->getKey()])->where('entry_date', '<', $from)
            ->selectRaw('coalesce(sum(debit - credit), 0)::numeric(20,2)::text AS base, coalesce(sum(amount_txn), 0)::numeric(20,2)::text AS txn')->first();
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')
            ->where(['l.company_id' => $company->getKey(), 'l.account_id' => $bankAccount->getKey()])->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('e.number')->orderBy('l.line_no')
            ->get(['l.entry_date as date', 'e.number as entry', 'e.source_type', 'e.memo', 'l.description', 'c.name as contact',
                'l.debit', 'l.credit', 'l.amount_txn']);

        $balance = Money::of((string) ($foreign ? $opening->txn : $opening->base), $foreign ? $bankAccount->currency : $base);
        $lines = $rows->map(function ($r) use (&$balance, $foreign, $bankAccount, $base) {
            $move = $foreign ? Money::of((string) $r->amount_txn, $bankAccount->currency) : Money::of((string) $r->debit, $base)->minus(Money::of((string) $r->credit, $base));
            $balance = $balance->plus($move);

            // A foreign account's line also in the company's currency, and the rate it was booked at (none for a
            // revaluation, which changes only the value in the company's currency).
            $inBase = Money::of((string) $r->debit, $base)->minus(Money::of((string) $r->credit, $base));
            $rate = $foreign && ! $move->isZero() ? $inBase->amount->dividedBy($move->amount->abs(), 6, \App\Domain\Ledger\Rounding::halfUp())->abs() : null;

            return ['date' => $r->date, 'entry' => $r->entry, 'source_type' => $r->source_type, 'memo' => $r->memo, 'description' => $r->description,
                'contact' => $r->contact, 'in' => $move->isNegative() ? '0.00' : $move->toDecimalString(),
                'out' => $move->isNegative() ? $move->negated()->toDecimalString() : '0.00', 'balance' => $balance->toDecimalString(),
                ...($foreign ? ['amount_base' => $inBase->toDecimalString(), 'rate' => $rate === null ? null : (string) $rate] : [])];
        });

        return response()->json(['account' => $this->present($bankAccount, $this->balances([$bankAccount->getKey()])[$bankAccount->getKey()] ?? null), 'currency' => $foreign ? $bankAccount->currency : $base, 'from' => $from, 'to' => $to,
            'opening' => $foreign ? (string) $opening->txn : (string) $opening->base, 'lines' => $lines, 'closing' => $balance->toDecimalString(),
            ...($foreign ? ['base_currency' => $base, 'opening_base' => (string) $opening->base] : [])]);
    }

    /** @param list<string> $ids @return array<string, object{base: string, txn: string}> */
    private function balances(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('journal_lines')->where('company_id', $this->current->id())->whereIn('account_id', $ids)->groupBy('account_id')
            ->selectRaw('account_id, sum(debit - credit)::numeric(20,2)::text AS base, sum(amount_txn)::numeric(20,2)::text AS txn')
            ->get()->keyBy('account_id')->all();
    }

    /** @return array<string, mixed> */
    private function present(Account $a, ?object $balance): array
    {
        $base = $this->current->get()->base_currency;
        $foreign = $a->currency !== null && $a->currency !== $base;
        $short = $a->float_amount === null ? null : BigDecimal::of((string) $a->float_amount)->minus(BigDecimal::of((string) ($balance->base ?? '0')));

        return [
            'id' => $a->getKey(), 'code' => $a->code, 'name' => $a->name, 'kind' => $a->system_key === 'undeposited_funds' ? 'undeposited_funds' : $a->subtype,
            'currency' => $a->currency ?? $base, 'is_active' => (bool) $a->is_active, 'bank_details' => (object) ($a->bank_details ?? []),
            'balance' => $foreign ? ($balance->txn ?? '0.00') : ($balance->base ?? '0.00'),
            'balance_base' => $balance->base ?? '0.00',
            // A petty cash float, and what it takes to bring the box back up to it.
            'float_amount' => $a->float_amount === null ? null : (string) $a->float_amount,
            'top_up' => $short === null ? null : (string) ($short->isNegative() ? BigDecimal::zero() : $short)->toScale(2),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function detailRules(): array
    {
        return [
            'bank_details' => ['sometimes', 'array:' . implode(',', self::DETAILS)],
            'bank_details.bank_name' => ['nullable', 'string', 'max:100'],
            'bank_details.account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z -]{4,40}$/'],
            'bank_details.branch' => ['nullable', 'string', 'max:100'],
            'bank_details.provider' => ['nullable', 'string', 'max:40'],
            'bank_details.wallet_number' => ['nullable', 'string', 'max:30', 'regex:/^\\+?[0-9 ()-]{6,30}$/'],
        ];
    }

    /** @return array<string, string> the bank details without empty parts */
    private function details(array $data): array
    {
        return array_filter((array) ($data['bank_details'] ?? []), fn ($v) => $v !== null && $v !== '');
    }

    private function can(): array
    {
        $m = $this->current->membership();

        return ['manage' => (bool) $m?->can('banking.manage'), 'transfer' => (bool) $m?->can('banking.transfer'), 'deposit' => (bool) $m?->can('banking.deposit')];
    }
}
