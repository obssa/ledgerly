<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Actions\SaveInvoiceDraft;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 9a: a fixed-fee project billed in steps (049). A milestone is a part of the contract, given as an amount or as a
 * share of the contract value (progress billing); billing it makes a draft invoice to the project's customer, tagged
 * with the project. Once billed on an invoice that stands, the database keeps it as billed.
 */
final class ProjectMilestoneController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    /**
     * The milestones of a project with what became of each: planned (not billed, or its invoice voided), drafted (on
     * a draft invoice), invoiced (posted, still owed) or paid.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(string $companyId, string $projectId): array
    {
        return DB::table('project_milestones as m')
            ->leftJoin('documents as i', fn ($j) => $j->on('i.company_id', '=', 'm.company_id')->on('i.id', '=', 'm.invoice_id'))
            ->where(['m.company_id' => $companyId, 'm.project_id' => $projectId])
            ->orderBy('m.position')->orderBy('m.due_on')->orderBy('m.created_at')
            ->get(['m.id', 'm.name', 'm.amount', 'm.due_on', 'm.position', 'i.id as invoice_id', 'i.number as invoice_number', 'i.status as invoice_status'])
            ->map(function ($m) {
                $standing = $m->invoice_id !== null && $m->invoice_status !== 'void';

                return [
                    'id' => $m->id, 'name' => $m->name, 'amount' => (string) $m->amount, 'due_on' => $m->due_on, 'position' => (int) $m->position,
                    'status' => ! $standing ? 'planned' : match ($m->invoice_status) {
                        'draft', 'awaiting_approval' => 'drafted',
                        'paid' => 'paid',
                        default => 'invoiced',
                    },
                    'invoice' => $standing ? ['id' => $m->invoice_id, 'number' => $m->invoice_number, 'status' => $m->invoice_status] : null,
                ];
            })->values()->all();
    }

    public function store(Request $request, string $project): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->project($project);
        $data = $this->validated($request, $p);
        $id = (string) Str::uuid();
        $position = (int) DB::table('project_milestones')->where(['company_id' => $this->current->id(), 'project_id' => $p->id])->max('position') + 1;
        DB::transaction(function () use ($id, $p, $data, $position, $request) {
            DB::table('project_milestones')->insert(['id' => $id, 'company_id' => $this->current->id(), 'project_id' => $p->id,
                'name' => $data['name'], 'amount' => $data['amount'], 'due_on' => $data['due_on'] ?? null, 'position' => $position]);
            $this->audit->record($this->current->id(), 'milestone_added', 'project', $p->id, null,
                ['milestone' => $id, 'name' => $data['name'], 'amount' => $data['amount'], 'due_on' => $data['due_on'] ?? null], userId: $request->user()->getKey());
        });

        return response()->json(['data' => self::listFor($this->current->id(), $p->id)], 201);
    }

    public function update(Request $request, string $project, string $milestone): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->project($project);
        $m = $this->milestone($p->id, $milestone);
        $data = $this->validated($request, $p);
        $set = ['name' => $data['name'], 'amount' => $data['amount'], 'due_on' => $data['due_on'] ?? null];
        $this->guarded(fn () => DB::transaction(function () use ($m, $p, $set, $request) {
            DB::table('project_milestones')->where('id', $m->id)->update($set);
            $this->audit->record($this->current->id(), 'milestone_changed', 'project', $p->id,
                ['milestone' => $m->id, 'name' => $m->name, 'amount' => (string) $m->amount, 'due_on' => $m->due_on], ['milestone' => $m->id, ...$set],
                userId: $request->user()->getKey());
        }));

        return response()->json(['data' => self::listFor($this->current->id(), $p->id)]);
    }

    public function destroy(Request $request, string $project, string $milestone): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->project($project);
        $m = $this->milestone($p->id, $milestone);
        $this->guarded(fn () => DB::transaction(function () use ($m, $p, $request) {
            DB::table('project_milestones')->where('id', $m->id)->delete();
            $this->audit->record($this->current->id(), 'milestone_removed', 'project', $p->id,
                ['milestone' => $m->id, 'name' => $m->name, 'amount' => (string) $m->amount], null, userId: $request->user()->getKey());
        }));

        return response()->json(['data' => self::listFor($this->current->id(), $p->id)]);
    }

    /** Bills a milestone: a draft invoice to the project's customer with one line for it, tagged with the project. */
    public function bill(Request $request, string $project, string $milestone, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $p = $this->project($project);
        $this->milestone($p->id, $milestone);   // another project's milestone is not found here, whatever else is wrong
        $data = $request->validate([
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'income_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')->where('is_active', true)],
            'tax_rate_id' => ['nullable', 'uuid'],
        ]);
        if ($p->customer_id === null) {
            throw ValidationException::withMessages(['project' => ["{$p->code} has no customer to bill: give it one first."]]);
        }

        return DB::transaction(function () use ($request, $data, $p, $milestone, $save) {
            $m = $this->milestone($p->id, $milestone, lock: true);
            if ($m->invoice_id !== null && DB::table('documents')->where('id', $m->invoice_id)->where('status', '<>', 'void')->exists()) {
                throw ValidationException::withMessages(['milestone' => ['This milestone is already on an invoice.']]);
            }
            $invoice = $save->handle([
                'contact_id' => $p->customer_id, 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'] ?? null,
                'currency' => $this->current->get()->base_currency, 'notes' => "{$p->code} {$p->name}: {$m->name}",
                'lines' => [['description' => mb_substr("{$m->name} ({$p->code} {$p->name})", 0, 500), 'quantity' => '1', 'unit_price' => (string) $m->amount,
                    'account_id' => $data['income_account_id'], 'tax_rate_id' => $data['tax_rate_id'] ?? null, 'project_id' => $p->id]],
            ], null, $request->user()->getKey());
            DB::table('project_milestones')->where('id', $m->id)->update(['invoice_id' => $invoice->getKey()]);
            $this->audit->record($this->current->id(), 'milestone_billed', 'project', $p->id, null,
                ['milestone' => $m->id, 'name' => $m->name, 'amount' => (string) $m->amount, 'invoice' => $invoice->getKey()], userId: $request->user()->getKey());

            return response()->json(['invoice' => ['id' => $invoice->getKey(), 'total' => (string) $invoice->total], 'data' => self::listFor($this->current->id(), $p->id)], 201);
        });
    }

    /** @return array{name: string, amount: string, due_on?: ?string} the amount given, or a share of the contract value */
    private function validated(Request $request, object $p): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'amount' => ['required_without:percent', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999999'],
            'percent' => ['required_without:amount', 'nullable', 'decimal:0,2', 'gt:0', 'max:100'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (($data['amount'] ?? null) === null) {
            if ($p->contract_value === null || bccomp((string) $p->contract_value, '0', 2) <= 0) {
                throw ValidationException::withMessages(['percent' => ['Give the project a contract value first, or enter the amount.']]);
            }
            // The share, rounded half up to the cent (amounts and percentages here are never negative).
            $share = bcdiv(bcmul((string) $p->contract_value, (string) $data['percent'], 6), '100', 6);
            $data['amount'] = bcadd($share, '0.005', 2);
        }
        unset($data['percent']);
        $data['name'] = trim($data['name']);

        return $data;
    }

    private function project(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('dimensions as d')->join('projects as p', fn ($j) => $j->on('p.company_id', '=', 'd.company_id')->on('p.dimension_id', '=', 'd.id'))
            ->where(['d.company_id' => $this->current->id(), 'd.id' => $id, 'd.kind' => 'project'])
            ->first(['d.id', 'd.code', 'd.name', 'p.customer_id', 'p.contract_value']) ?? abort(404);
    }

    private function milestone(string $projectId, string $id, bool $lock = false): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('project_milestones')->where(['company_id' => $this->current->id(), 'project_id' => $projectId, 'id' => $id])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? abort(404);
    }

    /** The database keeps a billed milestone as billed (049): its refusal is the person's answer. */
    private function guarded(\Closure $work): void
    {
        try {
            $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23514') {
                throw ValidationException::withMessages(['milestone' => ['This milestone is on an invoice: void the invoice to change or remove it.']]);
            }
            throw $e;
        }
    }
}
