<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assets\FixedAssets;
use App\Domain\Assets\TaxDepreciation;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 9c: the fixed asset register and the monthly depreciation run. An asset's cost is already in the ledger (from
 * its bill, or the opening balances); registering it records how it wears out. The run posts each month's charge.
 */
final class FixedAssetController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly FixedAssets $assets, private readonly AuditLogger $audit)
    {
    }

    /** The register: each asset with its cost, depreciation so far and book value; and the runs posted. */
    public function index(): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $companyId = $this->current->id();
        $rows = $this->query()->orderBy('a.status')->orderBy('a.code')->limit(2000)->get();
        $charged = DB::table('asset_depreciation')->where('company_id', $companyId)->groupBy('asset_id')->select('asset_id')
            ->selectRaw('sum(amount)::numeric(20,2)::text AS total')->pluck('total', 'asset_id');
        $impaired = $this->assets->impaired($companyId, $rows->pluck('id')->all());   // 091
        $revalued = $this->assets->revalued($companyId, $rows->pluck('id')->all());   // 092
        $runs = DB::table('depreciation_runs as r')->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'r.company_id')->on('e.id', '=', 'r.journal_entry_id'))
            ->where('r.company_id', $companyId)->orderByDesc('r.period_end')->limit(24)->get(['r.id', 'r.period_end', 'r.total', 'e.id as entry_id', 'e.number']);
        $membership = $this->current->membership();

        return response()->json([
            'data' => $rows->map(function ($a) use ($charged, $impaired, $revalued) {
                $depreciated = BigDecimal::of((string) $a->opening_depreciation)->plus((string) ($charged[$a->id] ?? '0'))->plus((string) ($impaired[$a->id] ?? '0'))->toScale(2);

                return [...$this->row($a), 'depreciated' => (string) $depreciated,
                    'book_value' => (string) BigDecimal::of((string) $a->cost)->minus($depreciated)->plus($revalued[$a->id]['net'] ?? '0')->toScale(2)];
            })->values(),
            'runs' => $runs->map(fn ($r) => ['id' => $r->id, 'period_end' => $r->period_end, 'total' => (string) $r->total, 'entry' => ['id' => $r->entry_id, 'number' => (int) $r->number]])->values(),
            'next_period_end' => $this->nextPeriodEnd(),
            'can' => ['manage' => (bool) $membership?->can('assets.manage'), 'depreciate' => (bool) $membership?->can('assets.depreciate')],
        ]);
    }

    public function show(string $fixedAsset): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $a = $this->load($fixedAsset);

        return response()->json([
            ...$this->row($a), ...$this->assets->schedule($a),
            'depreciated_by_runs' => DB::table('asset_depreciation')->where(['company_id' => $a->company_id, 'asset_id' => $a->id])->exists(),
            // 092: the equity accounts a revaluation surplus can be kept on.
            'surplus_accounts' => \App\Domain\Ledger\Models\Account::query()->where(['type' => 'equity', 'is_active' => true])->whereNull('system_key')
                ->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($x) => ['id' => $x->id, 'code' => $x->code, 'name' => $x->name])->values(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('assets.manage')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $data = $this->validated($request, null);
        $id = (string) Str::uuid7();
        $this->guarded(fn () => DB::table('fixed_assets')->insert(['id' => $id, 'company_id' => $this->current->id(), ...$data, 'created_by' => $request->user()->getKey()]));
        $this->audit->record($this->current->id(), 'asset_registered', 'fixed_asset', $id, null, $data, userId: $request->user()->getKey());

        return $this->show($id)->setStatusCode(201);
    }

    /** Edit an asset. Once depreciated, only its name, category, location and keeper change (042). */
    public function update(Request $request, string $fixedAsset): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $before = $this->load($fixedAsset);
        $data = $this->validated($request, $before);
        $this->guarded(fn () => DB::table('fixed_assets')->where(['company_id' => $this->current->id(), 'id' => $before->id])->update([...$data, 'updated_at' => now()]));
        $this->audit->record($this->current->id(), 'asset_updated', 'fixed_asset', $before->id, $this->row($before), $data, userId: $request->user()->getKey());

        return $this->show($before->id);
    }

    /** Sell or scrap an asset: its gain or loss against its book value is posted, and it leaves the register. */
    public function dispose(Request $request, string $fixedAsset): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $a = $this->load($fixedAsset);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'proceeds' => ['nullable', 'decimal:0,2', 'min:0'],
            'bank_account_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $this->assets->dispose($this->current->get(), $a->id, $data['date'], $data['proceeds'] ?? '0', $data['bank_account_id'] ?? null, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'asset_disposed', 'fixed_asset', $a->id, ['status' => 'active'],
            ['status' => 'disposed', 'date' => $data['date'], ...$result, 'entry' => $result['entry']['number']], $data['reason'] ?? null, userId: $request->user()->getKey());

        return $this->show($a->id);
    }

    /** What the run for a month end would post, asset by asset. */
    /** 091: writes the asset down when it is worth less than its carrying amount (damaged, idle, obsolete). */
    public function impair(Request $request, string $fixedAsset): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $a = $this->load($fixedAsset);   // the company's own, or not found
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Say why it is worth less: damaged, idle, obsolete.']);
        $done = $this->assets->impair($this->current->get(), (string) $a->id, $data['date'], (string) $data['amount'], $data['reason'], $request->user()->getKey());
        $this->audit->record($this->current->id(), 'asset_impaired', 'fixed_asset', (string) $a->id, null,
            ['amount' => $done['amount'], 'date' => $data['date'], 'journal_entry' => $done['entry']['number']], $data['reason'], $request->user()->getKey());

        return $this->show((string) $a->id)->setStatusCode(201);
    }

    /** 092: revalues the asset to its fair value (IFRS for SMEs 17.15B), the surplus on an equity account. */
    public function revalue(Request $request, string $fixedAsset): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $a = $this->load($fixedAsset);   // the company's own, or not found
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'fair_value' => ['required', 'decimal:0,2', 'gte:0', 'max:999999999999'],
            'surplus_account_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Say how the fair value was found: a valuer, a market price.']);
        $surplus = \App\Domain\Ledger\Models\Account::query()->whereKey($data['surplus_account_id'])->where(['type' => 'equity', 'is_active' => true])->first();
        if ($surplus === null || $surplus->system_key !== null) {
            throw ValidationException::withMessages(['surplus_account_id' => ['Choose the equity account the revaluation surplus is kept on.']]);
        }
        $done = $this->assets->revalue($this->current->get(), (string) $a->id, $data['date'], (string) $data['fair_value'], $surplus->getKey(), $data['reason'], $request->user()->getKey());
        $this->audit->record($this->current->id(), 'asset_revalued', 'fixed_asset', (string) $a->id, ['carrying' => $done['carrying_before']],
            ['fair_value' => $done['fair_value'], 'to_surplus' => $done['to_surplus'], 'to_profit' => $done['to_profit'], 'date' => $data['date'],
                'journal_entry' => $done['entry']['number']], $data['reason'], $request->user()->getKey());

        return $this->show((string) $a->id)->setStatusCode(201);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $periodEnd = $this->periodEnd($request);
        $charges = $this->assets->charges($this->current->get(), $periodEnd);

        return response()->json([
            'period_end' => $periodEnd,
            'lines' => array_map(fn (array $c) => ['asset_id' => $c['asset']->id, 'code' => $c['asset']->code, 'name' => $c['asset']->name, 'amount' => $c['amount']], $charges),
            'total' => (string) array_reduce($charges, fn (BigDecimal $t, array $c) => $t->plus($c['amount']), BigDecimal::zero())->toScale(2),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeCompany('assets.depreciate');
        $periodEnd = $this->periodEnd($request);
        $result = $this->assets->run($this->current->get(), $periodEnd, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'depreciation_posted', 'depreciation_run', $result['run'], null,
            ['period_end' => $periodEnd, 'total' => $result['total'], 'assets' => $result['assets'], 'entry' => $result['entry']['number']], userId: $request->user()->getKey());

        return response()->json($result, 201);
    }

    /** A month end up to the end of this month; the next one to run by default. */
    private function periodEnd(Request $request): string
    {
        $limit = FixedAssets::monthEnd($this->current->today());
        $value = $request->validate(['period_end' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $limit]],
            ['period_end.before_or_equal' => "Depreciation runs up to this month's end ({$limit})."])['period_end'] ?? $this->nextPeriodEnd();
        if ($value !== FixedAssets::monthEnd($value)) {
            throw ValidationException::withMessages(['period_end' => ['Depreciation runs for a month: choose the last day of a month.']]);
        }

        return $value;
    }

    /** The month after the latest run, or the earliest month an asset starts in, or this month. */
    private function nextPeriodEnd(): string
    {
        $companyId = $this->current->id();
        $latest = DB::table('depreciation_runs')->where('company_id', $companyId)->max('period_end');
        if ($latest !== null) {
            return FixedAssets::monthEnd((new \DateTimeImmutable($latest))->modify('+1 day')->format('Y-m-d'));
        }
        $first = DB::table('fixed_assets')->where(['company_id' => $companyId, 'status' => 'active'])->min('depreciation_starts_on');

        return FixedAssets::monthEnd($first ?? $this->current->today());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?object $before): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9._\/-]{1,30}$/'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['nullable', 'string', 'max:60'],
            'location_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'location')],
            'custodian' => ['nullable', 'string', 'max:120'],
            'asset_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'asset')->where('subtype', 'fixed_asset')->where('is_active', true)],
            'depreciation_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'asset')->where('subtype', 'contra_asset')->where('is_active', true)],
            'expense_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'expense')->where('is_active', true)],
            'acquired_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'cost' => ['required', 'decimal:0,2', 'gt:0'],
            'residual_value' => ['nullable', 'decimal:0,2', 'min:0'],
            'method' => ['required', Rule::in(['straight_line', 'reducing_balance'])],
            'useful_life_months' => ['required_if:method,straight_line', 'nullable', 'integer', 'between:1,1200'],
            'annual_rate' => ['required_if:method,reducing_balance', 'nullable', 'decimal:0,6', 'gt:0', 'lt:1'],
            'depreciation_starts_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:acquired_on'],
            'opening_depreciation' => ['nullable', 'decimal:0,2', 'min:0'],
            'source_document_id' => ['nullable', 'uuid', Rule::exists('documents', 'id')->whereIn('type', ['bill', 'expense'])],
            // The tax law's depreciation, when it differs from the books (061): a schedule only, nothing posts.
            'tax_method' => ['nullable', Rule::in(['straight_line', 'reducing_balance'])],
            'tax_useful_life_months' => ['required_if:tax_method,straight_line', 'nullable', 'integer', 'between:1,1200'],
            'tax_annual_rate' => ['required_if:tax_method,reducing_balance', 'nullable', 'decimal:0,6', 'gt:0', 'lt:1'],
            'tax_opening_depreciation' => ['nullable', 'decimal:0,2', 'min:0', 'lte:cost'],
        ], ['tax_useful_life_months.required_if' => 'Give the months tax depreciates it over.', 'tax_annual_rate.required_if' => 'Give the tax rate, such as 0.25 for 25%.',
            'tax_opening_depreciation.lte' => 'Tax depreciation before Ledgerly is never more than the cost.','asset_account_id.exists' => 'Choose a fixed asset account.', 'depreciation_account_id.exists' => 'Choose an accumulated depreciation (contra asset) account.',
            'useful_life_months.required_if' => 'Give the useful life in months.', 'annual_rate.required_if' => 'Give the annual rate, such as 0.25 for 25%.']);
        $companyId = $this->current->id();
        if (DB::table('fixed_assets')->where(['company_id' => $companyId, 'code' => $data['code']])->when($before, fn ($q) => $q->where('id', '<>', $before->id))->exists()) {
            throw ValidationException::withMessages(['code' => ["Another asset has the code {$data['code']}."]]);
        }
        $residual = BigDecimal::of($data['residual_value'] ?? '0');
        $opening = BigDecimal::of($data['opening_depreciation'] ?? '0');
        if ($residual->plus($opening)->isGreaterThan($data['cost'])) {
            throw ValidationException::withMessages(['residual_value' => ['The residual value and the depreciation before Ledgerly add up to more than the cost.']]);
        }

        return [
            'code' => $data['code'], 'name' => trim($data['name']), 'category' => $data['category'] ?? null, 'location_id' => $data['location_id'] ?? null,
            'custodian' => $data['custodian'] ?? null, 'asset_account_id' => $data['asset_account_id'], 'depreciation_account_id' => $data['depreciation_account_id'],
            'expense_account_id' => $data['expense_account_id'], 'acquired_on' => $data['acquired_on'], 'cost' => $data['cost'],
            'residual_value' => (string) $residual, 'method' => $data['method'],
            'useful_life_months' => $data['method'] === 'straight_line' ? (int) $data['useful_life_months'] : null,
            'annual_rate' => $data['method'] === 'reducing_balance' ? $data['annual_rate'] : null,
            // By default an asset is depreciated from the month after it is acquired.
            'depreciation_starts_on' => $data['depreciation_starts_on'] ?? (new \DateTimeImmutable($data['acquired_on']))->modify('first day of next month')->format('Y-m-d'),
            'opening_depreciation' => (string) $opening, 'source_document_id' => $data['source_document_id'] ?? null,
            'tax_method' => $data['tax_method'] ?? null,
            'tax_useful_life_months' => ($data['tax_method'] ?? null) === 'straight_line' ? (int) $data['tax_useful_life_months'] : null,
            'tax_annual_rate' => ($data['tax_method'] ?? null) === 'reducing_balance' ? $data['tax_annual_rate'] : null,
            'tax_opening_depreciation' => ($data['tax_method'] ?? null) === null ? '0' : (string) ($data['tax_opening_depreciation'] ?? '0'),
        ];
    }

    /** Module 9c: the tax depreciation schedule of a fiscal year, beside the books (061). Nothing posts. */
    public function taxSchedule(Request $request, TaxDepreciation $tax): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $data = $request->validate(['year_end' => ['nullable', 'date_format:Y-m-d']]);
        $company = $this->current->get();
        $yearEnd = $data['year_end'] ?? (string) (DB::table('fiscal_years')->where('company_id', $company->getKey())
            ->where('starts_on', '<=', $this->current->today())->orderByDesc('starts_on')->value('ends_on') ?? self::monthEndOf($this->current->today()));
        if ($yearEnd !== self::monthEndOf($yearEnd)) {
            throw ValidationException::withMessages(['year_end' => ['A year ends on the last day of a month.']]);
        }

        return response()->json($tax->year($company, $yearEnd));
    }

    private static function monthEndOf(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
    }

    private function guarded(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                $message = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The asset could not be saved.';
                throw ValidationException::withMessages(['method' => [$message]]);
            }
            throw $e;
        }
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('fixed_assets as a')->leftJoin('dimensions as l', 'l.id', '=', 'a.location_id')
            ->join('accounts as acc', 'acc.id', '=', 'a.asset_account_id')
            ->where('a.company_id', $this->current->id())
            ->select('a.*', 'l.name as location_name', 'acc.code as asset_account_code', 'acc.name as asset_account_name');
    }

    private function load(string $id): object
    {
        $a = $this->query()->where('a.id', $id)->first();
        if ($a === null) {
            abort(404);
        }

        return $a;
    }

    /** @return array<string, mixed> */
    private function row(object $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'status' => $a->status,
            'location' => $a->location_id ? ['id' => $a->location_id, 'name' => $a->location_name] : null, 'custodian' => $a->custodian,
            'asset_account' => ['id' => $a->asset_account_id, 'code' => $a->asset_account_code, 'name' => $a->asset_account_name],
            'depreciation_account_id' => $a->depreciation_account_id, 'expense_account_id' => $a->expense_account_id,
            'acquired_on' => $a->acquired_on, 'cost' => (string) $a->cost, 'residual_value' => (string) $a->residual_value, 'method' => $a->method,
            'useful_life_months' => $a->useful_life_months === null ? null : (int) $a->useful_life_months,
            'annual_rate' => $a->annual_rate === null ? null : (string) $a->annual_rate, 'depreciation_starts_on' => $a->depreciation_starts_on,
            'opening_depreciation' => (string) $a->opening_depreciation, 'source_document_id' => $a->source_document_id,
            'tax' => ['method' => $a->tax_method, 'useful_life_months' => $a->tax_useful_life_months === null ? null : (int) $a->tax_useful_life_months,
                'annual_rate' => $a->tax_annual_rate === null ? null : (string) $a->tax_annual_rate, 'opening_depreciation' => (string) $a->tax_opening_depreciation],
            'disposal' => $a->status === 'disposed' ? ['date' => $a->disposed_on, 'proceeds' => (string) $a->proceeds, 'entry_id' => $a->disposal_entry_id] : null];
    }
}
