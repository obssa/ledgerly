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

        return response()->json([
            ...$this->row($p), ...$figures,
            'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null,
            'accounts' => $accounts->map(fn ($a) => ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'amount' => $a->amount])->values(),
            'documents' => $documents->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'number' => $d->number, 'date' => $d->issue_date,
                'contact' => $d->contact, 'currency' => $d->currency, 'amount' => $d->amount])->values(),
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
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['code.regex' => 'Letters, digits, dot, dash or underscore, up to 20.', 'code.prohibited' => 'A project keeps its code: add a new project instead.']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function details(array $data): array
    {
        return array_intersect_key([...array_fill_keys(['customer_id', 'starts_on', 'ends_on', 'contract_value', 'budget_income', 'budget_cost', 'notes'], null), ...$data],
            array_flip(['customer_id', 'status', 'billing', 'starts_on', 'ends_on', 'contract_value', 'budget_income', 'budget_cost', 'notes']));
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
                'p.contract_value', 'p.budget_income', 'p.budget_cost', 'p.notes');
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
            'budget_income' => $money($p->budget_income), 'budget_cost' => $money($p->budget_cost), 'notes' => $p->notes];
    }
}
