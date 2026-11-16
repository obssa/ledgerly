<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
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
        $years = DB::table('fiscal_years as y')->leftJoin('users as u', 'u.id', '=', 'y.closed_by')->where('y.company_id', $this->current->id())
            ->orderBy('y.starts_on')->get(['y.id', 'y.starts_on', 'y.ends_on', 'y.status', 'u.name as closed_by', 'y.closed_at']);
        $membership = $this->current->membership();
        $today = $this->current->today();
        $closable = $years->first(fn ($y) => in_array($y->status, ['open', 'reopened'], true));
        $reopenable = $years->where('status', 'closed')->last();

        return response()->json([
            'data' => $years->map(fn ($y) => [...(array) $y,
                'can_close' => $closable?->id === $y->id && $y->ends_on < $today && (bool) $membership?->can('periods.close'),
                'can_reopen' => $reopenable?->id === $y->id && (bool) $membership?->can('periods.reopen'),
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
