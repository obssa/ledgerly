<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assets\Leases;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\CurrentCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 9 (095): leases, as the lessee. The register with what is still owed and the payments to come by when they
 * fall due (within a year, in one to five years, later: IFRS for SMEs 20.16 and 20.30); a lease set up, its schedule,
 * each payment made in turn, and a lease ended.
 */
final class LeaseController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly Leases $leases, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $today = $this->current->today();
        $inAYear = Leases::addMonths($today, 12);
        $inFive = Leases::addMonths($today, 60);
        $zero = ['within_year' => '0.00', 'one_to_five' => '0.00', 'later' => '0.00'];
        $total = $zero;
        $rows = [];
        foreach ($this->query()->orderBy('l.status')->orderBy('l.code')->limit(500)->get() as $l) {
            $schedule = $this->leases->schedule($l);
            $due = $zero;
            foreach ($schedule as $p) {
                if ($p['paid_on'] !== null || $l->status !== 'active') {
                    continue;
                }
                $band = $p['due_on'] <= $inAYear ? 'within_year' : ($p['due_on'] <= $inFive ? 'one_to_five' : 'later');
                $due[$band] = bcadd($due[$band], $p['amount'], 2);
                $total[$band] = bcadd($total[$band], $p['amount'], 2);
            }
            $paid = array_values(array_filter($schedule, fn ($p) => $p['paid_on'] !== null));
            $rows[] = [...$this->row($l), 'paid' => count($paid),
                'owed' => $l->kind === 'finance' ? ($paid === [] ? (string) $l->present_value : end($paid)['owed']) : null,
                'to_pay' => $due];
        }
        $membership = $this->current->membership();

        return response()->json(['data' => $rows, 'to_pay' => $total, 'as_of' => $today,
            'can' => ['manage' => (bool) $membership?->can('assets.manage'), 'pay' => (bool) $membership?->can('payments.pay')]]);
    }

    public function show(string $lease): JsonResponse
    {
        $this->authorizeCompany('assets.view');
        $l = $this->load($lease);
        $membership = $this->current->membership();

        return response()->json([...$this->row($l), 'schedule' => $this->leases->schedule($l),
            'can' => ['manage' => (bool) $membership?->can('assets.manage'), 'pay' => (bool) $membership?->can('payments.pay')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $finance = $request->input('kind') === 'finance';
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9._\/-]{1,30}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'lessor_id' => ['nullable', 'uuid'],
            'kind' => ['required', Rule::in(['finance', 'operating'])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'months' => ['required', 'integer', 'between:1,600'],
            'payment' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            'in_advance' => ['boolean'],
            'annual_rate' => [$finance ? 'required' : 'prohibited', 'decimal:0,6', 'min:0', 'lt:1'],
            'liability_account_id' => [$finance ? 'required' : 'prohibited', 'uuid'],
            'interest_account_id' => [$finance ? 'required' : 'prohibited', 'uuid'],
            'asset_account_id' => [$finance ? 'required' : 'prohibited', 'uuid'],
            'depreciation_account_id' => [$finance ? 'required' : 'prohibited', 'uuid'],
            'depreciation_expense_account_id' => [$finance ? 'required' : 'prohibited', 'uuid'],
            'useful_life_months' => [$finance ? 'nullable' : 'prohibited', 'integer', 'between:1,1200'],
            'expense_account_id' => [$finance ? 'prohibited' : 'required', 'uuid'],
        ], ['code.regex' => 'Letters, digits, dot, slash, dash or underscore, up to 30.', 'annual_rate.lt' => 'The rate is a share of one: 0.12 for 12% a year.']);
        $companyId = $this->current->id();
        if (DB::table('leases')->where(['company_id' => $companyId, 'code' => $data['code']])->exists()
            || ($finance && DB::table('fixed_assets')->where(['company_id' => $companyId, 'code' => $data['code']])->exists())) {
            throw ValidationException::withMessages(['code' => ["Code {$data['code']} is already used for a lease or an asset."]]);
        }
        if (($data['lessor_id'] ?? null) !== null && ! DB::table('contacts')->where(['company_id' => $companyId, 'id' => $data['lessor_id']])->exists()) {
            throw ValidationException::withMessages(['lessor_id' => ['Choose the lessor from your contacts.']]);
        }
        $kinds = $finance
            ? ['liability_account_id' => ['liability', null, 'a liability account'], 'interest_account_id' => ['expense', null, 'an expense account'],
                'asset_account_id' => ['asset', 'fixed_asset', 'a fixed asset account'], 'depreciation_account_id' => ['asset', 'contra_asset', 'an accumulated depreciation account'],
                'depreciation_expense_account_id' => ['expense', null, 'an expense account']]
            : ['expense_account_id' => ['expense', null, 'an expense account']];
        foreach ($kinds as $field => [$type, $subtype, $what]) {
            $ok = DB::table('accounts')->where(['company_id' => $companyId, 'id' => $data[$field], 'type' => $type, 'is_active' => true])
                ->when($subtype, fn ($q, $v) => $q->where('subtype', $v))->exists();
            if (! $ok) {
                throw ValidationException::withMessages([$field => ["Choose {$what}."]]);
            }
        }
        try {
            $id = $this->leases->create($this->current->get(), $data, $request->user()->getKey());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23514') {
                throw $e;
            }
            $message = preg_match('/ERROR:\s+(.+?)(?:\s+CONTEXT:|\s+\(Connection:|\s+\(SQL:|$)/s', $e->getMessage(), $m) ? trim($m[1]) : 'The lease could not be set up.';
            throw ValidationException::withMessages(['kind' => [$message]]);
        }
        $l = $this->load($id);
        $this->audit->record($companyId, 'lease_created', 'lease', $id, null, $this->row($l), userId: $request->user()->getKey());

        return $this->show($id)->setStatusCode(201);
    }

    /** Pays the next payment due. */
    public function pay(Request $request, string $lease): JsonResponse
    {
        $this->authorizeCompany('payments.pay');
        $l = $this->load($lease);   // the company's own, or not found
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'bank_account_id' => ['required', 'uuid'],
        ]);
        $done = $this->leases->pay($this->current->get(), $l->id, $data['date'], $data['bank_account_id'], $request->user()->getKey());
        $this->audit->record($this->current->id(), 'lease_paid', 'lease', $l->id, null,
            [...$done, 'date' => $data['date'], 'entry' => $done['entry']['number']], userId: $request->user()->getKey());

        return $this->show($l->id)->setStatusCode(201);
    }

    /** Ends a lease (ended early, or paid in full): no more payments are taken. */
    public function end(Request $request, string $lease): JsonResponse
    {
        $this->authorizeCompany('assets.manage');
        $l = $this->load($lease);
        if ($l->status === 'ended') {
            throw ValidationException::withMessages(['lease' => ["Lease {$l->code} has ended already."]]);
        }
        DB::table('leases')->where(['company_id' => $this->current->id(), 'id' => $l->id])->update(['status' => 'ended', 'updated_at' => now()]);
        $this->audit->record($this->current->id(), 'lease_ended', 'lease', $l->id, ['status' => 'active'], ['status' => 'ended'], userId: $request->user()->getKey());

        return $this->show($l->id);
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('leases as l')->leftJoin('contacts as c', 'c.id', '=', 'l.lessor_id')
            ->where('l.company_id', $this->current->id())->select('l.*', 'c.name as lessor');
    }

    private function load(string $id): object
    {
        return $this->query()->where('l.id', $id)->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function row(object $l): array
    {
        return ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'kind' => $l->kind, 'status' => $l->status,
            'lessor' => $l->lessor_id ? ['id' => $l->lessor_id, 'name' => $l->lessor] : null,
            'starts_on' => $l->starts_on, 'months' => (int) $l->months, 'payment' => (string) $l->payment, 'in_advance' => (bool) $l->in_advance,
            'annual_rate' => $l->annual_rate === null ? null : (rtrim(rtrim((string) BigDecimal::of((string) $l->annual_rate)->toScale(6), '0'), '.') ?: '0'),
            'present_value' => $l->present_value === null ? null : (string) $l->present_value,
            'fixed_asset_id' => $l->fixed_asset_id, 'commencement_entry_id' => $l->commencement_entry_id];
    }
}
