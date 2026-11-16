<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\ManualJournal;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\YearEnd;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Module 6, year-end: the company's fiscal years, closing the earliest open one that has ended, reopening the latest. */
final class FiscalYearController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly YearEnd $yearEnd, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $years = DB::table('fiscal_years as y')->leftJoin('users as u', 'u.id', '=', 'y.closed_by')->leftJoin('users as s', 's.id', '=', 'y.adjustments_closed_by')
            ->where('y.company_id', $this->current->id())
            ->orderBy('y.starts_on')->get(['y.id', 'y.starts_on', 'y.ends_on', 'y.status', 'u.name as closed_by', 'y.closed_at', 'y.adjustments_closed_at', 's.name as signed_off_by']);
        $membership = $this->current->membership();
        $today = $this->current->today();
        $closable = $years->first(fn ($y) => in_array($y->status, ['open', 'reopened'], true));
        $reopenable = $years->where('status', 'closed')->last();
        $adjustments = DB::table('journal_entries')->where(['company_id' => $this->current->id(), 'source_type' => 'adjustment'])
            ->groupBy('source_id')->select('source_id')->selectRaw('count(*) AS n')->pluck('n', 'source_id');

        return response()->json([
            'data' => $years->map(fn ($y) => [...(array) $y,
                'can_close' => $closable?->id === $y->id && $y->ends_on < $today && (bool) $membership?->can('periods.close'),
                'can_reopen' => $reopenable?->id === $y->id && $y->adjustments_closed_at === null && (bool) $membership?->can('periods.reopen'),
                'adjustments' => (int) ($adjustments[$y->id] ?? 0),
                'can_adjust' => $reopenable?->id === $y->id && $y->adjustments_closed_at === null && (bool) $membership?->can('periods.reopen'),
            ])->values(),
        ]);
    }

    /** What closing the year moves to retained earnings, account by account. */
    public function preview(string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('periods.close');

        return response()->json($this->yearEnd->preview($this->current->get(), $this->year($fiscalYear)));
    }

    public function close(Request $request, string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('periods.close');
        $this->year($fiscalYear);
        $result = $this->yearEnd->close($this->current->get(), $fiscalYear, $request->user()->getKey(), $this->current->membership(), $this->current->today());
        $this->audit->record($this->current->id(), 'year_closed', 'fiscal_year', $fiscalYear, null,
            ['net_profit' => $result['net_profit'], 'closing_entry' => $result['entry']['number']], userId: $request->user()->getKey());

        return response()->json($result, 201);
    }

    public function reopen(Request $request, string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('periods.reopen');
        $this->year($fiscalYear);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.required' => 'Say why the closed year is being reopened.'])['reason'];
        $result = $this->yearEnd->reopen($this->current->get(), $fiscalYear, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'year_reopened', 'fiscal_year', $fiscalYear, ['status' => 'closed'],
            ['status' => 'reopened', 'reversal' => $result['reversal']['number']], $reason, userId: $request->user()->getKey());

        return response()->json($result);
    }

    /** The auditor's worksheet: balances at the year's last day before the close, the audit adjustments, and after them. */
    public function adjustedTrialBalance(string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $year = $this->year($fiscalYear);
        $rows = $this->yearEnd->adjustedTrialBalance($this->current->get(), $year);
        $entries = DB::table('journal_entries as e')->leftJoin('users as u', 'u.id', '=', 'e.posted_by')
            ->where(['e.company_id' => $this->current->id(), 'e.source_type' => 'adjustment', 'e.source_id' => $year->id])
            ->orderBy('e.number')->get(['e.id', 'e.number', 'e.memo', 'u.name as posted_by', 'e.posted_at']);
        $base = $this->current->get()->base_currency;
        $sum = fn (string $column) => array_reduce($rows, fn (Money $t, array $r) => $t->plus(Money::of($r[$column], $base)), Money::zero($base))->toDecimalString();

        return response()->json([
            'year' => ['id' => $year->id, 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on, 'status' => $year->status, 'adjustments_closed_at' => $year->adjustments_closed_at],
            'lines' => $rows,
            'totals' => ['unadjusted' => $sum('unadjusted'), 'adjustments' => $sum('adjustments'), 'adjusted' => $sum('adjusted')],
            'adjustments' => $entries->map(fn ($e) => ['id' => $e->id, 'number' => (int) $e->number, 'memo' => $e->memo, 'posted_by' => $e->posted_by, 'posted_at' => $e->posted_at])->values(),
            'can' => ['adjust' => $year->status === 'closed' && $year->adjustments_closed_at === null && (bool) $this->current->membership()?->can('periods.reopen')
                && ! DB::table('fiscal_years')->where('company_id', $this->current->id())->where('starts_on', '>', $year->ends_on)->where('status', 'closed')->exists()],
        ]);
    }

    /** Post an audit adjustment into the latest closed year (owners and admins): dated its last day, the year closed again with it. */
    public function adjust(Request $request, string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('periods.reopen');
        $this->year($fiscalYear);
        $rules = ManualJournal::rules('2999-12-31');
        unset($rules['date']);
        $data = $request->validate($rules, ['memo.required' => 'Say what the adjustment is, such as the auditor\'s reference.']);
        $result = $this->yearEnd->adjust($this->current->get(), $fiscalYear, $data['memo'], $data['lines'], $request->user()->getKey());
        $this->audit->record($this->current->id(), 'audit_adjustment_posted', 'fiscal_year', $fiscalYear, null,
            ['adjustment' => $result['adjustment']['number'], 'closing_entry' => $result['closing']['number'], 'net_profit' => $result['net_profit'], 'memo' => $data['memo']],
            userId: $request->user()->getKey());

        return response()->json($result, 201);
    }

    /** Sign the audit off (owners and admins): the year takes no more adjustments and stays closed. */
    public function finalise(Request $request, string $fiscalYear): JsonResponse
    {
        $this->authorizeCompany('periods.reopen');
        $this->year($fiscalYear);
        $this->yearEnd->finalise($this->current->get(), $fiscalYear, $request->user()->getKey());
        $this->audit->record($this->current->id(), 'audit_signed_off', 'fiscal_year', $fiscalYear, ['adjustments' => 'open'], ['adjustments' => 'signed off'],
            userId: $request->user()->getKey());

        return $this->adjustedTrialBalance($fiscalYear);
    }

    /** The year, if it is this company's; another company's is not found. */
    private function year(string $id): object
    {
        $year = DB::table('fiscal_years')->where(['company_id' => $this->current->id(), 'id' => $id])->first();
        if ($year === null) {
            abort(404);
        }

        return $year;
    }
}
