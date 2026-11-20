<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 9a: projects and job costing. A project is a dimension of kind 'project' with its details (040). Invoice,
 * credit note, bill, supplier credit and expense lines are tagged with it, and posting carries the tag to the ledger,
 * so a project's income, costs and profit are read from the ledger (income accounts and expense accounts tagged with
 * it), against its contract value and budget.
 */
final class ProjectController extends Controller
{
    private const OPEN = ['active', 'on_hold'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('projects.view');
        $rows = $this->query()->orderByRaw("CASE WHEN coalesce(p.status, 'active') IN ('active', 'on_hold') THEN 0 ELSE 1 END")->orderBy('d.code')->limit(500)->get();
        $figures = $this->figures($rows->pluck('id')->all(), null, null);

        return response()->json([
            'data' => $rows->map(fn ($p) => [...$this->row($p), ...($figures[$p->id] ?? ['income' => '0.00', 'costs' => '0.00', 'profit' => '0.00'])])->values(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('projects.manage')],
        ]);
    }

    /** One project: its details, and its income, costs and profit (all time, or for a period), account by account. */
    public function show(Request $request, string $project): JsonResponse
    {
        $this->authorizeCompany('projects.view');
        $p = $this->load($project);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $companyId = $this->current->id();
        $accounts = $this->ledger($companyId)->where('l.project_id', $p->id)
            ->when($q['from'] ?? null, fn ($b, $v) => $b->where('l.entry_date', '>=', $v))->when($q['to'] ?? null, fn ($b, $v) => $b->where('l.entry_date', '<=', $v))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.type', 'desc')->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END)::numeric(20,2)::text AS amount")->get();
        $documents = DB::table('document_lines as dl')
            ->join('documents as d', fn ($j) => $j->on('d.company_id', '=', 'dl.company_id')->on('d.id', '=', 'dl.document_id'))
            ->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->where(['dl.company_id' => $companyId, 'dl.project_id' => $p->id])->whereIn('d.status', ['posted', 'part_paid', 'paid'])
            ->groupBy('d.id', 'd.type', 'd.number', 'd.issue_date', 'c.name', 'd.currency')->orderByDesc('d.issue_date')->limit(100)
            ->select('d.id', 'd.type', 'd.number', 'd.issue_date', 'c.name as contact', 'd.currency')
            ->selectRaw('sum(dl.line_subtotal)::numeric(20,2)::text AS amount')->get();
        $figures = $this->figures([$p->id], $q['from'] ?? null, $q['to'] ?? null)[$p->id] ?? ['income' => '0.00', 'costs' => '0.00', 'profit' => '0.00'];
        // Billable hours not yet on an invoice that stands, valued at their rate or the project's.
        $unbilled = DB::table('time_entries as t')
            ->leftJoin('documents as i', fn ($j) => $j->on('i.company_id', '=', 't.company_id')->on('i.id', '=', 't.invoice_id'))
            ->where(['t.company_id' => $companyId, 't.project_id' => $p->id, 't.billable' => true])->where(fn ($w) => $w->whereNull('i.id')->orWhere('i.status', 'void'))
            ->selectRaw('coalesce(sum(t.hours), 0)::numeric(10,2)::text AS hours')
            ->selectRaw('coalesce(sum(round(t.hours * coalesce(t.rate, ?), 2)), 0)::numeric(20,2)::text AS value', [$p->hourly_rate ?? 0])
            ->selectRaw('count(*) FILTER (WHERE t.rate IS NULL) AS without_rate')->first();
        $logged = DB::table('time_entries')->where(['company_id' => $companyId, 'project_id' => $p->id])->sum('hours');

        return response()->json([
            ...$this->row($p), ...$figures,
            'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null,
            'time' => ['logged_hours' => number_format((float) $logged, 2, '.', ''), 'unbilled_hours' => $unbilled->hours, 'unbilled_value' => $unbilled->value,
                'unpriced' => $p->hourly_rate === null && (int) $unbilled->without_rate > 0],
            'accounts' => $accounts->map(fn ($a) => ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'amount' => $a->amount])->values(),
            'documents' => $documents->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'number' => $d->number, 'date' => $d->issue_date,
                'contact' => $d->contact, 'currency' => $d->currency, 'amount' => $d->amount])->values(),
            'milestones' => ProjectMilestoneController::listFor($companyId, $p->id),
            'grant' => $this->grantOf($p->id),   // 093
            'can' => ['manage' => (bool) $this->current->membership()?->can('projects.manage')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $data = $this->validated($request, true);
        $companyId = $this->current->id();
        if (DB::table('dimensions')->where(['company_id' => $companyId, 'kind' => 'project', 'code' => $data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => ["A project with the code {$data['code']} already exists."]]);
        }
        $id = (string) Str::uuid7();
        DB::table('dimensions')->insert(['id' => $id, 'company_id' => $companyId, 'kind' => 'project', 'code' => $data['code'], 'name' => $data['name'],
            'is_active' => in_array($data['status'], self::OPEN, true)]);
        DB::table('projects')->insert(['dimension_id' => $id, 'company_id' => $companyId, ...$this->details($data)]);
        $this->audit->record($companyId, 'project_created', 'project', $id, null, ['code' => $data['code'], 'name' => $data['name'], ...$this->details($data)],
            userId: $request->user()->getKey());

        return $this->show($request, $id)->setStatusCode(201);
    }

    /** Edit the details; a completed or cancelled project is turned off, so nothing new is tagged with it. */
    public function update(Request $request, string $project): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->load($project);
        $data = $this->validated($request, false);
        $companyId = $this->current->id();
        DB::table('dimensions')->where(['company_id' => $companyId, 'id' => $p->id])
            ->update(['name' => $data['name'], 'is_active' => in_array($data['status'], self::OPEN, true), 'updated_at' => now()]);
        $exists = DB::table('projects')->where(['company_id' => $companyId, 'dimension_id' => $p->id])->exists();
        $exists
            ? DB::table('projects')->where(['company_id' => $companyId, 'dimension_id' => $p->id])->update([...$this->details($data), 'updated_at' => now()])
            : DB::table('projects')->insert(['dimension_id' => $p->id, 'company_id' => $companyId, ...$this->details($data)]);
        $this->audit->record($companyId, 'project_updated', 'project', $p->id, $this->row($p), ['name' => $data['name'], ...$this->details($data)],
            userId: $request->user()->getKey());

        return $this->show($request, $p->id);
    }

    /**
     * 093: sets the project up as a grant: the donor, the award, the donor's reference, whether it is restricted, and
     * its budget, one line per expense account (the lines given replace the ones it had).
     */
    public function grant(Request $request, string $project): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->load($project);   // the company's own, or not found
        $data = $request->validate([
            'donor_id' => ['nullable', 'uuid'],
            'award' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:100'],
            'restricted' => ['required', 'boolean'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999'],
        ]);
        $companyId = $this->current->id();
        if (($data['donor_id'] ?? null) !== null && ! DB::table('contacts')->where(['company_id' => $companyId, 'id' => $data['donor_id']])->exists()) {
            throw ValidationException::withMessages(['donor_id' => ['Choose the donor from your contacts.']]);
        }
        $ids = array_column($data['lines'], 'account_id');
        $expense = DB::table('accounts')->where(['company_id' => $companyId, 'type' => 'expense'])->whereIn('id', $ids)->pluck('id')->flip();
        foreach ($ids as $i => $id) {
            if (! isset($expense[$id])) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['A budget line is an expense account.']]);
            }
            if (array_search($id, $ids, true) !== $i) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => ['Each expense account is one budget line.']]);
            }
        }
        $before = $this->grantOf($p->id);
        $reference = isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null;
        DB::transaction(function () use ($companyId, $p, $data, $reference) {
            $row = ['donor_id' => $data['donor_id'] ?? null, 'award' => (string) $data['award'], 'reference' => $reference, 'restricted' => (bool) $data['restricted'], 'updated_at' => now()];
            DB::table('project_grants')->where(['company_id' => $companyId, 'project_id' => $p->id])->exists()
                ? DB::table('project_grants')->where(['company_id' => $companyId, 'project_id' => $p->id])->update($row)
                : DB::table('project_grants')->insert(['project_id' => $p->id, 'company_id' => $companyId, ...$row]);
            DB::table('grant_budget_lines')->where(['company_id' => $companyId, 'project_id' => $p->id])->delete();
            DB::table('grant_budget_lines')->insert(array_map(fn (array $l) => ['id' => (string) Str::uuid7(), 'company_id' => $companyId, 'project_id' => $p->id,
                'account_id' => $l['account_id'], 'amount' => (string) $l['amount']], $data['lines']));
        });
        $after = $this->grantOf($p->id);
        $this->audit->record($companyId, 'grant_set', 'project', $p->id, $before, $after, userId: $request->user()->getKey());

        return $this->show($request, $p->id);
    }

    /**
     * 093: the donor report for a period (from the project's start, or the beginning, to today by default): each budget
     * line's budget, what was spent in the period and to date, and what is left; spending on other accounts; the income
     * the grant brought in; and the fund balance (income less spending, to date). Read from the ledger by the project.
     */
    public function donorReport(Request $request, string $project): JsonResponse
    {
        $this->authorizeCompany('projects.view');
        $p = $this->load($project);
        $grant = $this->grantOf($p->id)
            ?? throw ValidationException::withMessages(['project' => ["{$p->code} is not set up as a grant: add its donor, award and budget first."]]);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = $q['to'] ?? $this->current->today();
        $from = $q['from'] ?? ($p->starts_on !== null && (string) $p->starts_on <= $to ? (string) $p->starts_on : null);
        $companyId = $this->current->id();
        $moves = $this->ledger($companyId)->where('l.project_id', $p->id)->whereIn('a.type', ['income', 'expense'])->where('l.entry_date', '<=', $to)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END)::numeric(20,2)::text AS to_date")
            ->selectRaw("coalesce(sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END) FILTER (WHERE l.entry_date >= ?), 0)::numeric(20,2)::text AS period", [$from ?? '0001-01-01'])
            ->get()->keyBy('id');
        $zero = '0.00';
        $lines = [];
        $total = ['budget' => $zero, 'period' => $zero, 'to_date' => $zero, 'remaining' => $zero];
        foreach ($grant['lines'] as $b) {
            $m = $moves[$b['account_id']] ?? null;
            $toDate = $m->to_date ?? $zero;
            $lines[] = ['account_id' => $b['account_id'], 'code' => $b['code'], 'name' => $b['name'], 'budget' => $b['amount'], 'period' => $m->period ?? $zero,
                'to_date' => $toDate, 'remaining' => bcsub($b['amount'], $toDate, 2), 'over' => bccomp($toDate, $b['amount'], 2) > 0];
            foreach (['budget' => $b['amount'], 'period' => $m->period ?? $zero, 'to_date' => $toDate] as $k => $v) {
                $total[$k] = bcadd($total[$k], $v, 2);
            }
        }
        $total['remaining'] = bcsub($total['budget'], $total['to_date'], 2);
        $budgeted = array_flip(array_column($grant['lines'], 'account_id'));
        $outside = $moves->filter(fn ($m) => $m->type === 'expense' && ! isset($budgeted[$m->id]) && (bccomp($m->to_date, '0', 2) !== 0 || bccomp($m->period, '0', 2) !== 0))
            ->map(fn ($m) => ['account_id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'period' => $m->period, 'to_date' => $m->to_date])->sortBy('code')->values();
        $spent = $moves->filter(fn ($m) => $m->type === 'expense');
        $income = $moves->filter(fn ($m) => $m->type === 'income');
        $sum = fn ($set, string $k) => $set->reduce(fn (string $t, $m) => bcadd($t, $m->{$k}, 2), $zero);

        return response()->json([
            'project' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on],
            'grant' => $grant, 'from' => $from, 'to' => $to,
            'lines' => $lines, 'totals' => $total, 'outside_budget' => $outside,
            'spent' => ['period' => $sum($spent, 'period'), 'to_date' => $sum($spent, 'to_date')],
            'income' => ['period' => $sum($income, 'period'), 'to_date' => $sum($income, 'to_date')],
            'fund_balance' => bcsub($sum($income, 'to_date'), $sum($spent, 'to_date'), 2),
            'award_to_come' => bcsub($grant['award'], $sum($income, 'to_date'), 2),
        ]);
    }

    /** @return array<string, mixed>|null the project's grant (093), with its budget lines */
    private function grantOf(string $projectId): ?array
    {
        $g = DB::table('project_grants as g')->leftJoin('contacts as c', 'c.id', '=', 'g.donor_id')
            ->where(['g.company_id' => $this->current->id(), 'g.project_id' => $projectId])->first(['g.donor_id', 'c.name as donor', 'g.award', 'g.reference', 'g.restricted']);
        if ($g === null) {
            return null;
        }
        $lines = DB::table('grant_budget_lines as b')->join('accounts as a', 'a.id', '=', 'b.account_id')
            ->where(['b.company_id' => $this->current->id(), 'b.project_id' => $projectId])->orderBy('a.code')->get(['b.account_id', 'a.code', 'a.name', 'b.amount']);

        return ['donor' => $g->donor_id ? ['id' => $g->donor_id, 'name' => $g->donor] : null, 'award' => (string) $g->award, 'reference' => $g->reference,
            'restricted' => (bool) $g->restricted,
            'lines' => $lines->map(fn ($l) => ['account_id' => $l->account_id, 'code' => $l->code, 'name' => $l->name, 'amount' => (string) $l->amount])->values()->all(),
            'budget' => (string) $lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->amount, 2), '0.00')];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[A-Za-z0-9._-]{1,20}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('is_customer', true)],
            'status' => ['required', Rule::in(['active', 'on_hold', 'completed', 'cancelled'])],
            'billing' => ['required', Rule::in(['fixed_fee', 'time_and_materials', 'non_billable'])],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'contract_value' => ['nullable', 'decimal:0,2', 'min:0'],
            'budget_income' => ['nullable', 'decimal:0,2', 'min:0'],
            'budget_cost' => ['nullable', 'decimal:0,2', 'min:0'],
            'hourly_rate' => ['nullable', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['code.regex' => 'Letters, digits, dot, dash or underscore, up to 20.', 'code.prohibited' => 'A project keeps its code: add a new project instead.']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function details(array $data): array
    {
        $keys = ['customer_id', 'starts_on', 'ends_on', 'contract_value', 'budget_income', 'budget_cost', 'hourly_rate', 'notes'];

        return array_intersect_key([...array_fill_keys($keys, null), ...$data], array_flip(['status', 'billing', ...$keys]));
    }

    /** Income (income accounts) and costs (expense accounts) tagged with each project, from the ledger. @param list<string> $ids */
    private function figures(array $ids, ?string $from, ?string $to): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->ledger($this->current->id())->whereIn('l.project_id', $ids)->whereIn('a.type', ['income', 'expense'])
            ->when($from, fn ($b, $v) => $b->where('l.entry_date', '>=', $v))->when($to, fn ($b, $v) => $b->where('l.entry_date', '<=', $v))
            ->groupBy('l.project_id')->select('l.project_id')
            ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE 0 END)::numeric(20,2)::text AS income")
            ->selectRaw("sum(CASE WHEN a.type = 'expense' THEN l.debit - l.credit ELSE 0 END)::numeric(20,2)::text AS costs")
            ->selectRaw("sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit WHEN a.type = 'expense' THEN l.credit - l.debit ELSE 0 END)::numeric(20,2)::text AS profit")
            ->get()->mapWithKeys(fn ($r) => [$r->project_id => ['income' => $r->income, 'costs' => $r->costs, 'profit' => $r->profit]])->all();
    }

    private function ledger(string $companyId): \Illuminate\Database\Query\Builder
    {
        // Closing entries move a year's profit to retained earnings; a project's profit is what it earned and spent.
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'l.company_id')->on('e.id', '=', 'l.journal_entry_id'))
            ->where('l.company_id', $companyId)->where('e.source_type', '<>', 'closing');
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('dimensions as d')
            ->leftJoin('projects as p', fn ($j) => $j->on('p.company_id', '=', 'd.company_id')->on('p.dimension_id', '=', 'd.id'))
            ->leftJoin('contacts as c', 'c.id', '=', 'p.customer_id')
            ->where(['d.company_id' => $this->current->id(), 'd.kind' => 'project'])
            ->select('d.id', 'd.code', 'd.name', 'd.is_active', 'p.customer_id', 'c.name as customer', 'p.status', 'p.billing', 'p.starts_on', 'p.ends_on',
                'p.contract_value', 'p.budget_income', 'p.budget_cost', 'p.hourly_rate', 'p.notes');
    }

    /** The project, if it is this company's; another company's is not found. */
    private function load(string $id): object
    {
        $p = $this->query()->where('d.id', $id)->first();
        if ($p === null) {
            abort(404);
        }

        return $p;
    }

    /** @return array<string, mixed> */
    private function row(object $p): array
    {
        $money = fn ($v) => $v === null ? null : (string) $v;

        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_active' => (bool) $p->is_active,
            'customer' => $p->customer_id ? ['id' => $p->customer_id, 'name' => $p->customer] : null,
            'status' => $p->status ?? ($p->is_active ? 'active' : 'completed'), 'billing' => $p->billing ?? 'fixed_fee',
            'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on, 'contract_value' => $money($p->contract_value),
            'budget_income' => $money($p->budget_income), 'budget_cost' => $money($p->budget_cost), 'hourly_rate' => $money($p->hourly_rate), 'notes' => $p->notes];
    }
}
