<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Money;
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
 * Module 9a: time tracking and billing time. Anyone who logs time (projects.time) enters their own hours against an
 * active project, billable or not; whoever manages projects sees and edits everyone's and bills them: the billable,
 * unbilled hours become a draft invoice for the project's customer, one line per entry at its rate (or the project's
 * hourly rate), tagged with the project. A billed entry is fixed until its invoice is voided (041).
 */
final class TimeEntryController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    /** Hours, newest first: everyone's for those who manage projects, one's own otherwise. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('projects.time');
        $q = $request->validate([
            'project_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'unbilled' => ['nullable', 'boolean'],
        ]);
        $all = $this->managing();
        $rows = $this->query()
            ->when(! $all, fn ($b) => $b->where('t.user_id', $request->user()->getKey()))
            ->when($q['project_id'] ?? null, fn ($b, $v) => $b->where('t.project_id', $v))
            ->when($q['from'] ?? null, fn ($b, $v) => $b->where('t.work_date', '>=', $v))->when($q['to'] ?? null, fn ($b, $v) => $b->where('t.work_date', '<=', $v))
            ->when($q['unbilled'] ?? false, fn ($b) => $b->where('t.billable', true)->where(fn ($w) => $w->whereNull('i.id')->orWhere('i.status', 'void')))
            ->orderByDesc('t.work_date')->orderByDesc('t.created_at')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn ($t) => $this->row($t))->values(),
            'can' => ['manage' => $all],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('projects.time');
        $data = $this->validated($request);
        $id = (string) Str::uuid7();
        DB::table('time_entries')->insert(['id' => $id, 'company_id' => $this->current->id(), ...$data, 'created_by' => $request->user()->getKey()]);
        $this->audit->record($this->current->id(), 'time_logged', 'time_entry', $id, null, $data, userId: $request->user()->getKey());

        return response()->json($this->row($this->load($id)), 201);
    }

    public function update(Request $request, string $timeEntry): JsonResponse
    {
        $this->authorizeCompany('projects.time');
        $before = $this->editable($request, $timeEntry);
        $data = $this->validated($request);
        $this->guarded(fn () => DB::table('time_entries')->where(['company_id' => $this->current->id(), 'id' => $before->id])->update([...$data, 'updated_at' => now()]));
        $this->audit->record($this->current->id(), 'time_changed', 'time_entry', $before->id, $this->row($before), $data, userId: $request->user()->getKey());

        return response()->json($this->row($this->load($before->id)));
    }

    public function destroy(Request $request, string $timeEntry): JsonResponse
    {
        $this->authorizeCompany('projects.time');
        $before = $this->editable($request, $timeEntry);
        $this->guarded(fn () => DB::table('time_entries')->where(['company_id' => $this->current->id(), 'id' => $before->id])->delete());
        $this->audit->record($this->current->id(), 'time_removed', 'time_entry', $before->id, $this->row($before), null, userId: $request->user()->getKey());

        return response()->json(null, 204);
    }

    /**
     * Bill a project's billable, unbilled hours (up to a date, or the ones chosen) on a draft invoice for its customer:
     * one line per entry, hours at its rate or the project's, to an income account, tagged with the project.
     */
    public function bill(Request $request, string $project, SaveInvoiceDraft $save): JsonResponse
    {
        $this->authorizeCompany('projects.manage');
        $companyId = $this->current->id();
        // Loaded first: another company's project is not found, whatever the body says.
        $p = DB::table('dimensions as d')->leftJoin('projects as p', fn ($j) => $j->on('p.company_id', '=', 'd.company_id')->on('p.dimension_id', '=', 'd.id'))
            ->where(['d.company_id' => $companyId, 'd.id' => $project, 'd.kind' => 'project'])->first(['d.id', 'd.code', 'd.name', 'p.customer_id', 'p.hourly_rate']);
        if ($p === null) {
            abort(404);
        }
        $data = $request->validate([
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'income_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')->where('is_active', true)],
            'up_to' => ['nullable', 'date_format:Y-m-d'],
            'entry_ids' => ['nullable', 'array', 'max:500'],
            'entry_ids.*' => ['uuid', 'distinct'],
        ]);
        if ($p->customer_id === null) {
            throw ValidationException::withMessages(['project' => ["{$p->code} has no customer to bill: give it one first."]]);
        }

        return DB::transaction(function () use ($request, $data, $p, $save, $companyId) {
            $entries = $this->query()->where('t.project_id', $p->id)->where('t.billable', true)
                ->where(fn ($w) => $w->whereNull('i.id')->orWhere('i.status', 'void'))
                ->when($data['up_to'] ?? null, fn ($b, $v) => $b->where('t.work_date', '<=', $v))
                ->when($data['entry_ids'] ?? null, fn ($b, $v) => $b->whereIn('t.id', $v))
                ->orderBy('t.work_date')->orderBy('t.created_at')->lock('for update of t')->get();   // the entries, not the invoices they may name
            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['entry_ids' => ['No billable hours are waiting to be billed on this project.']]);
            }
            $base = $this->current->get()->base_currency;
            $lines = [];
            foreach ($entries as $t) {
                $rate = $t->rate ?? $p->hourly_rate;
                if ($rate === null) {
                    throw ValidationException::withMessages(['entry_ids' => ["The hours of {$t->work_date} have no rate: give them one, or give the project an hourly rate."]]);
                }
                $lines[] = ['description' => mb_substr("{$t->work_date} {$t->user_name}" . ($t->note ? ": {$t->note}" : ''), 0, 500),
                    'quantity' => (string) $t->hours, 'unit_price' => Money::of((string) $rate, $base)->toDecimalString(),
                    'account_id' => $data['income_account_id'], 'project_id' => $p->id];
            }
            $invoice = $save->handle(['contact_id' => $p->customer_id, 'issue_date' => $data['issue_date'], 'currency' => $base,
                'notes' => "Time on {$p->code} {$p->name}", 'lines' => $lines], null, $request->user()->getKey());
            DB::table('time_entries')->where('company_id', $companyId)->whereIn('id', $entries->pluck('id')->all())
                ->update(['invoice_id' => $invoice->getKey(), 'updated_at' => now()]);
            $this->audit->record($companyId, 'time_billed', 'project', $p->id, null,
                ['invoice' => $invoice->getKey(), 'entries' => $entries->count(), 'hours' => (string) $entries->sum('hours'), 'total' => (string) $invoice->total],
                userId: $request->user()->getKey());

            return response()->json(['invoice' => ['id' => $invoice->getKey(), 'total' => (string) $invoice->total], 'entries' => $entries->count()], 201);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid', Rule::exists('dimensions', 'id')->where('kind', 'project')->where('is_active', true)],
            'user_id' => ['nullable', 'uuid'],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'hours' => ['required', 'decimal:0,2', 'gt:0', 'max:24'],
            'billable' => ['required', 'boolean'],
            'rate' => ['nullable', 'decimal:0,2', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['project_id.exists' => 'Choose an active project.']);
        $me = $request->user()->getKey();
        $user = $data['user_id'] ?? $me;
        if ($user !== $me && ! $this->managing()) {
            throw ValidationException::withMessages(['user_id' => ['You log your own hours; whoever manages projects logs them for others.']]);
        }
        if (! DB::table('memberships')->where(['company_id' => $this->current->id(), 'user_id' => $user, 'status' => 'active'])->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Choose a member of the company.']]);
        }

        return ['project_id' => $data['project_id'], 'user_id' => $user, 'work_date' => $data['work_date'], 'hours' => $data['hours'],
            'billable' => (bool) $data['billable'], 'rate' => $data['rate'] ?? null, 'note' => $data['note'] ?? null];
    }

    /**
     * The entry, if the person may change it and it is not billed. Someone else's hours are not found for those who
     * see only their own (as in the list).
     */
    private function editable(Request $request, string $id): object
    {
        $t = $this->load($id);
        if ($t->user_id !== $request->user()->getKey() && ! $this->managing()) {
            abort(404);
        }
        if ($t->invoice_id !== null && $t->invoice_status !== 'void') {
            throw ValidationException::withMessages(['entry' => ['These hours are billed: void the invoice to change them.']]);
        }

        return $t;
    }

    private function managing(): bool
    {
        return (bool) $this->current->membership()?->can('projects.manage');
    }

    private function guarded(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw ValidationException::withMessages(['entry' => ['These hours are billed: void the invoice to change them.']]);
            }
            throw $e;
        }
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('time_entries as t')
            ->join('dimensions as d', 'd.id', '=', 't.project_id')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('documents as i', fn ($j) => $j->on('i.company_id', '=', 't.company_id')->on('i.id', '=', 't.invoice_id'))
            ->where('t.company_id', $this->current->id())
            ->select('t.id', 't.project_id', 'd.code as project_code', 'd.name as project_name', 't.user_id', 'u.name as user_name', 't.work_date', 't.hours',
                't.billable', 't.rate', 't.note', 't.invoice_id', 'i.number as invoice_number', 'i.status as invoice_status', 't.created_at');
    }

    private function load(string $id): object
    {
        $t = $this->query()->where('t.id', $id)->first();
        if ($t === null) {
            abort(404);
        }

        return $t;
    }

    /** @return array<string, mixed> */
    private function row(object $t): array
    {
        $billed = $t->invoice_id !== null && $t->invoice_status !== 'void';

        return ['id' => $t->id, 'project' => ['id' => $t->project_id, 'code' => $t->project_code, 'name' => $t->project_name],
            'user' => ['id' => $t->user_id, 'name' => $t->user_name], 'work_date' => $t->work_date, 'hours' => (string) $t->hours,
            'billable' => (bool) $t->billable, 'rate' => $t->rate === null ? null : (string) $t->rate, 'note' => $t->note,
            'invoice' => $billed ? ['id' => $t->invoice_id, 'number' => $t->invoice_number, 'status' => $t->invoice_status] : null];
    }
}
