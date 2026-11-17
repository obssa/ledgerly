<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Ledger\Models\Account;
use App\Domain\Reports\SavedReports;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 10: a person's saved reports (045), each with its period and options, and a weekly or monthly email that
 * links to it. Row-level security shows each person only their own; someone else's id is a 404.
 */
final class SavedReportController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly SavedReports $saved, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('reports.view');

        return response()->json(['data' => DB::table('saved_reports')->where('company_id', $this->current->id())->where('user_id', auth()->id())
            ->orderBy('name')->limit(200)->get()->map(fn ($r) => $this->present($r))]);
    }

    public function show(string $savedReport): JsonResponse
    {
        $this->authorizeCompany('reports.view');

        return response()->json($this->present($this->find($savedReport)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $data = $this->validated($request);
        $id = (string) \Illuminate\Support\Str::uuid7();
        DB::table('saved_reports')->insert(['id' => $id, 'company_id' => $this->current->id(), 'user_id' => $request->user()->getKey(), ...$data]);
        $row = $this->find($id);
        $this->audit->record($this->current->id(), 'report_saved', 'saved_report', $id, null, $this->summary($row), userId: $request->user()->getKey());

        return response()->json($this->present($row), 201);
    }

    public function update(Request $request, string $savedReport): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $before = $this->find($savedReport);   // someone else's, or another company's: 404 before the body is read
        $data = $this->validated($request);
        DB::table('saved_reports')->where('id', $before->id)->update($data);
        $row = $this->find($before->id);
        $this->audit->record($this->current->id(), 'saved_report_changed', 'saved_report', $row->id, $this->summary($before), $this->summary($row), userId: $request->user()->getKey());

        return response()->json($this->present($row));
    }

    public function destroy(Request $request, string $savedReport): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $row = $this->find($savedReport);
        DB::table('saved_reports')->where('id', $row->id)->delete();
        $this->audit->record($this->current->id(), 'saved_report_removed', 'saved_report', $row->id, $this->summary($row), null, userId: $request->user()->getKey());

        return response()->json(['removed' => true]);
    }

    /** @return array<string, mixed> the columns to store */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'report' => ['required', Rule::in(SavedReports::REPORTS)],
            'period' => ['required', Rule::in(SavedReports::PERIODS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'schedule' => ['nullable', Rule::in(SavedReports::SCHEDULES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'string', 'max:60'],
        ]);
        $custom = $data['period'] === 'custom';
        $atADate = in_array($data['report'], SavedReports::AT_A_DATE, true);
        $from = $custom && ! $atADate ? ($data['from'] ?? null) : null;
        if ($custom && ! $atADate && $from === null) {
            throw ValidationException::withMessages(['from' => ['A custom period needs its first day.']]);
        }
        if ($from !== null && $data['to'] < $from) {
            throw ValidationException::withMessages(['to' => ['The period ends on or after it starts.']]);
        }
        $schedule = $data['schedule'] ?? 'none';

        return [
            'name' => trim($data['name']), 'report' => $data['report'], 'period' => $data['period'],
            'from_date' => $from, 'to_date' => $custom ? $data['to'] : null,
            'options' => json_encode((object) $this->options($data['report'], $data['options'] ?? []), JSON_THROW_ON_ERROR),   // {} when none, never []
            'schedule' => $schedule, 'next_send_on' => $this->saved->nextSend($schedule, $this->current->today()),
        ];
    }

    /**
     * Only the options the report takes, each one of its allowed values; an account or warehouse is loaded through
     * its company-scoped model (CLAUDE.md rule 4).
     *
     * @param array<string, mixed> $given @return array<string, string>
     */
    private function options(string $report, array $given): array
    {
        $out = [];
        foreach (SavedReports::OPTIONS[$report] ?? [] as $key => $allowed) {
            $value = $given[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $ok = match (true) {
                $allowed !== 'uuid' => in_array($value, $allowed, true),
                $key === 'account_id' => \Illuminate\Support\Str::isUuid($value) && Account::query()->whereKey($value)->exists(),
                default => \Illuminate\Support\Str::isUuid($value) && Warehouse::query()->whereKey($value)->exists(),
            };
            if (! $ok) {
                throw ValidationException::withMessages(["options.{$key}" => ['Choose one of the values this report offers.']]);
            }
            $out[$key] = $value;
        }
        if ($report === 'general-ledger' && ! isset($out['account_id'])) {
            throw ValidationException::withMessages(['options.account_id' => ['A general ledger is saved for one account.']]);
        }

        return $out;
    }

    private function find(string $id): object
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            abort(404);
        }

        return DB::table('saved_reports')->where('company_id', $this->current->id())->where('user_id', auth()->id())->where('id', $id)->first() ?? abort(404);
    }

    /** @return array<string, mixed> a saved report with the dates its period covers today */
    private function present(object $r): array
    {
        $dates = $this->saved->period($this->current->id(), $r->period, $r->from_date, $r->to_date, $this->current->today());

        return ['id' => $r->id, 'name' => $r->name, 'report' => $r->report, 'period' => $r->period, 'period_label' => SavedReports::periodLabel($r->period),
            'from_date' => $r->from_date, 'to_date' => $r->to_date, 'options' => json_decode((string) $r->options, true) ?: (object) [],
            'schedule' => $r->schedule, 'next_send_on' => $r->next_send_on, 'last_sent_at' => $r->last_sent_at,
            'covers' => in_array($r->report, SavedReports::AT_A_DATE, true) ? ['as_of' => $dates['to']] : $dates];
    }

    /** @return array<string, mixed> */
    private function summary(object $r): array
    {
        return ['name' => $r->name, 'report' => $r->report, 'period' => $r->period, 'from_date' => $r->from_date, 'to_date' => $r->to_date,
            'options' => json_decode((string) $r->options, true), 'schedule' => $r->schedule];
    }
}
