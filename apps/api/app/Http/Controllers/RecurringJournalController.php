<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ManualJournal;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\RecurringJournals;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 6: recurring manual journals (rent, depreciation, a standing accrual). A template is checked like a manual
 * journal when it is set up and again each time it posts; it posts without approval, so its total stays within the
 * company's approval limit for journals. The scheduler posts what is due every hour (ledgerly:post-recurring); anyone
 * who may post journals can post what is due now, pause, resume or edit a template. Editing or resuming makes the
 * template post as you (036).
 */
final class RecurringJournalController extends Controller
{
    private const LINE_KEYS = ['account_id', 'debit', 'credit', 'description', 'location_id', 'class_id', 'project_id'];

    public function __construct(private readonly CurrentCompany $current, private readonly RecurringJournals $recurring, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $ids = DB::table('recurring_journals')->where('company_id', $this->current->id())
            ->orderByDesc('is_active')->orderBy('next_on')->orderBy('name')->limit(500)->pluck('id');
        $today = $this->current->today();

        return response()->json([
            'data' => $ids->map(fn (string $id) => $this->present($id, withLines: false))->values(),
            'due' => DB::table('recurring_journals')->where(['company_id' => $this->current->id(), 'is_active' => true])->where('next_on', '<=', $today)->count(),
            'can' => ['create' => (bool) $this->current->membership()?->can('journals.create')],
        ]);
    }

    public function show(string $recurringJournal): JsonResponse
    {
        $this->authorizeCompany('accounting.view');

        return response()->json($this->present($this->load($recurringJournal)->id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $data = $this->validated($request);
        $id = (string) Str::uuid7();
        DB::table('recurring_journals')->insert([
            'id' => $id, 'company_id' => $this->current->id(), ...$data, 'posts_as' => $request->user()->getKey(),
        ]);
        $this->audit->record($this->current->id(), 'recurring_journal_created', 'recurring_journal', $id, null,
            ['name' => $data['name'], 'total' => $data['total'], 'frequency' => $data['frequency'], 'next_on' => $data['next_on'], 'ends_on' => $data['ends_on']],
            userId: $request->user()->getKey());

        return response()->json($this->present($id), 201);
    }

    /** Edit: the lines, the schedule or the name. The template then posts as you, and is active again if it was paused. */
    public function update(Request $request, string $recurringJournal): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $before = $this->load($recurringJournal);
        $data = $this->validated($request);
        DB::table('recurring_journals')->where(['company_id' => $this->current->id(), 'id' => $before->id])->update([
            ...$data, 'posts_as' => $request->user()->getKey(), 'is_active' => true, 'last_error' => null, 'updated_at' => now(),
        ]);
        $this->audit->record($this->current->id(), 'recurring_journal_updated', 'recurring_journal', $before->id,
            ['name' => $before->name, 'total' => (string) $before->total, 'frequency' => $before->frequency, 'next_on' => $before->next_on, 'ends_on' => $before->ends_on, 'is_active' => $before->is_active],
            ['name' => $data['name'], 'total' => $data['total'], 'frequency' => $data['frequency'], 'next_on' => $data['next_on'], 'ends_on' => $data['ends_on'], 'is_active' => true],
            userId: $request->user()->getKey());

        return response()->json($this->present($before->id));
    }

    public function pause(Request $request, string $recurringJournal): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $t = $this->load($recurringJournal);
        if (! $t->is_active) {
            throw ValidationException::withMessages(['is_active' => ['This recurring journal is already paused.']]);
        }
        DB::table('recurring_journals')->where(['company_id' => $this->current->id(), 'id' => $t->id])->update(['is_active' => false, 'updated_at' => now()]);
        $this->audit->record($this->current->id(), 'recurring_journal_paused', 'recurring_journal', $t->id, ['is_active' => true], ['is_active' => false], userId: $request->user()->getKey());

        return response()->json($this->present($t->id));
    }

    /** Resume from the next date, or a new one (to skip dates while it was paused); it then posts as you. */
    public function resume(Request $request, string $recurringJournal): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $t = $this->load($recurringJournal);
        $nextOn = $request->validate(['next_on' => ['nullable', 'date_format:Y-m-d']])['next_on'] ?? (string) $t->next_on;
        if ($t->is_active) {
            throw ValidationException::withMessages(['is_active' => ['This recurring journal is already running.']]);
        }
        if ($t->ends_on !== null && $nextOn > $t->ends_on) {
            throw ValidationException::withMessages(['next_on' => ["It ended on {$t->ends_on}: edit it to give it a later end date first."]]);
        }
        $this->assertWithinLimit((string) $t->total);
        DB::table('recurring_journals')->where(['company_id' => $this->current->id(), 'id' => $t->id])->update([
            'is_active' => true, 'next_on' => $nextOn, 'last_error' => null, 'posts_as' => $request->user()->getKey(), 'updated_at' => now(),
        ]);
        $this->audit->record($this->current->id(), 'recurring_journal_resumed', 'recurring_journal', $t->id,
            ['is_active' => false, 'next_on' => $t->next_on], ['is_active' => true, 'next_on' => $nextOn], userId: $request->user()->getKey());

        return response()->json($this->present($t->id));
    }

    /** Post every template that has come due by today now, as you, rather than waiting for the scheduler. */
    public function postDue(Request $request): JsonResponse
    {
        $this->authorizeCompany('journals.create');
        $company = $this->current->get();
        $today = $this->current->today();
        $results = DB::table('recurring_journals')->where(['company_id' => $company->getKey(), 'is_active' => true])->where('next_on', '<=', $today)
            ->orderBy('next_on')->limit(200)->pluck('name', 'id')
            ->map(fn (string $name, string $id) => ['id' => $id, 'name' => $name, ...$this->recurring->postDue($company, $id, $request->user()->getKey(), $today)])
            ->values();

        return response()->json(['data' => $results, 'posted' => $results->sum(fn ($r) => count($r['posted']))]);
    }

    /** @return array<string, mixed> the template's columns, checked like a manual journal */
    private function validated(Request $request): array
    {
        $data = $request->validate(RecurringJournals::rules(), ['memo.required' => 'Say what the entry is for: the memo is how it is found again.']);
        $lines = array_map(fn (array $line) => array_filter(array_intersect_key($line, array_flip(self::LINE_KEYS)), fn ($v) => $v !== null && $v !== ''),
            array_values($data['lines']));
        $draft = ManualJournal::draft($this->current->get(), $data['next_on'], $data['memo'], $lines);
        $total = $draft->totalDebit()->toDecimalString();
        $this->assertWithinLimit($total);

        return [
            'name' => trim($data['name']), 'memo' => $data['memo'], 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total' => $total,
            'frequency' => $data['frequency'], 'next_on' => $data['next_on'], 'ends_on' => $data['ends_on'] ?? null,
        ];
    }

    private function assertWithinLimit(string $total): void
    {
        $company = $this->current->get();
        $limit = ManualJournal::approvalLimit($company);
        if ($limit !== null && $limit->minus(Money::of($total, $company->base_currency))->isNegative()) {
            throw ValidationException::withMessages(['lines' => [
                "A recurring journal posts without approval, so its total ({$total}) stays within the approval limit for journals ({$limit->toDecimalString()}).",
            ]]);
        }
    }

    /** The template, if it is this company's; another company's is not found. */
    private function load(string $id): object
    {
        $t = DB::table('recurring_journals')->where(['company_id' => $this->current->id(), 'id' => $id])->first();
        if ($t === null) {
            abort(404);
        }

        return $t;
    }

    /** @return array<string, mixed> */
    private function present(string $id, bool $withLines = true): array
    {
        $companyId = $this->current->id();
        $t = $this->load($id);
        $lines = json_decode((string) $t->lines, true, 512, JSON_THROW_ON_ERROR);
        $accounts = $withLines ? DB::table('accounts')->where('company_id', $companyId)->whereIn('id', array_column($lines, 'account_id'))->get(['id', 'code', 'name'])->keyBy('id') : collect();
        $entries = $withLines ? DB::table('journal_entries')->where(['company_id' => $companyId, 'source_type' => 'manual', 'source_id' => $t->id])
            ->orderByDesc('number')->limit(24)->get(['id', 'number', 'entry_date', 'status']) : collect();

        return [
            'id' => $t->id, 'name' => $t->name, 'memo' => $t->memo, 'total' => (string) $t->total, 'frequency' => $t->frequency,
            'next_on' => $t->next_on, 'ends_on' => $t->ends_on, 'is_active' => (bool) $t->is_active, 'posted_count' => (int) $t->posted_count,
            'last_posted_on' => $t->last_posted_on, 'last_error' => $t->last_error,
            'posts_as' => DB::table('users')->where('id', $t->posts_as)->value('name'),
            'lines' => $withLines ? array_map(fn (array $l) => [
                'account_id' => $l['account_id'], 'code' => $accounts[$l['account_id']]->code ?? null, 'account' => $accounts[$l['account_id']]->name ?? null,
                'debit' => $l['debit'] ?? null, 'credit' => $l['credit'] ?? null, 'description' => $l['description'] ?? null,
                'location_id' => $l['location_id'] ?? null, 'class_id' => $l['class_id'] ?? null, 'project_id' => $l['project_id'] ?? null,
            ], $lines) : null,
            'entries' => $withLines ? $entries->map(fn ($e) => ['id' => $e->id, 'number' => (int) $e->number, 'date' => $e->entry_date, 'status' => $e->status])->values() : null,
            'can' => ['edit' => (bool) $this->current->membership()?->can('journals.create')],
        ];
    }
}
