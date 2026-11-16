<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Revaluation;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 6, month-end revaluation: see what foreign balances would be restated at a date and its rates, then post the
 * revaluation (reversed the next day). Every run is kept with its rates and report (034).
 */
final class RevaluationController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly Revaluation $revaluation, private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('accounting.view');
        $runs = DB::table('fx_revaluations as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->join('journal_entries as e', fn ($j) => $j->on('e.company_id', '=', 'r.company_id')->on('e.id', '=', 'r.journal_entry_id'))
            ->join('journal_entries as x', fn ($j) => $j->on('x.company_id', '=', 'r.company_id')->on('x.id', '=', 'r.reversal_entry_id'))
            ->where('r.company_id', $this->current->id())->orderByDesc('r.as_of')->limit(120)
            ->get(['r.id', 'r.as_of', 'r.rates', 'r.lines', 'r.net', 'u.name as by', 'r.created_at', 'e.id as entry_id', 'e.number as entry', 'x.id as reversal_id', 'x.number as reversal', 'x.entry_date as reversed_on']);

        return response()->json([
            'data' => $runs->map(fn ($r) => [
                'id' => $r->id, 'as_of' => $r->as_of, 'rates' => (object) json_decode((string) $r->rates, true), 'lines' => json_decode((string) $r->lines, true),
                'net' => (string) $r->net, 'by' => $r->by, 'at' => $r->created_at,
                'entry' => ['id' => $r->entry_id, 'number' => (int) $r->entry], 'reversal' => ['id' => $r->reversal_id, 'number' => (int) $r->reversal, 'date' => $r->reversed_on],
            ])->values(),
            'can' => ['revalue' => (bool) $this->current->membership()?->can('accounting.revalue')],
        ]);
    }

    /** What a revaluation at the date would restate, with the company's rate for each currency (or the rates given). */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.revalue');
        $data = $this->validated($request);

        return response()->json($this->revaluation->preview($this->current->get(), $data['as_of'], $data['rates'] ?? []));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('accounting.revalue');
        $data = $this->validated($request);
        $result = $this->revaluation->run($this->current->get(), $data['as_of'], $data['rates'] ?? [], $request->user()->getKey());
        $this->audit->record($this->current->id(), 'revalued', 'fx_revaluation', $result['id'], null,
            ['as_of' => $data['as_of'], 'rates' => $result['rates'], 'net' => $result['net'], 'entry' => $result['entry']['number'], 'reversal' => $result['reversal']['number']],
            userId: $request->user()->getKey());

        return response()->json($result, 201);
    }

    /** @return array{as_of: string, rates?: array<string, string>} a date before today (it is reversed the next day) and rates by currency */
    private function validated(Request $request): array
    {
        $today = $this->current->today();

        return $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d', 'before:' . $today],
            'rates' => ['nullable', 'array', 'max:20'],
            'rates.*' => ['decimal:0,6', 'gt:0'],
        ], ['as_of.before' => 'Revalue at a day before today: the revaluation is reversed the next day.']);
    }
}
