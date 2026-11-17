<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\AnalysisReports;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Module 10, management reports: profit by location, class or project; sales and purchases by party, item or month. */
final class AnalysisReportController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AnalysisReports $reports)
    {
    }

    public function profitByDimension(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $kind = $request->validate(['kind' => ['nullable', Rule::in(array_keys(AnalysisReports::DIMENSIONS))]])['kind'] ?? 'location';
        [$from, $to] = $this->period($request);

        return response()->json(['kind' => $kind, 'from' => $from, 'to' => $to, 'currency' => $this->current->get()->base_currency,
            ...$this->reports->profitByDimension($this->current->id(), $kind, $from, $to)]);
    }

    public function sales(Request $request): JsonResponse
    {
        return $this->documents($request, 'sales');
    }

    public function purchases(Request $request): JsonResponse
    {
        return $this->documents($request, 'purchases');
    }

    private function documents(Request $request, string $side): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $group = $request->validate(['group' => ['nullable', Rule::in(AnalysisReports::GROUPS)]])['group'] ?? 'contact';
        [$from, $to] = $this->period($request);

        return response()->json(['side' => $side, 'group' => $group, 'from' => $from, 'to' => $to, 'currency' => $this->current->get()->base_currency,
            ...$this->reports->documents($this->current->id(), $side, $group, $from, $to)]);
    }

    /** @return array{0: string, 1: string} the current fiscal year to today unless given; up to five years */
    private function period(Request $request): array
    {
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $q['to'] ?? $this->current->today();
        $from = $q['from'] ?? (DB::table('fiscal_years')->where('company_id', $this->current->id())->where('starts_on', '<=', $to)->where('ends_on', '>=', $to)->value('starts_on')
            ?? (new \DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d'));
        if ($to < $from || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 1830) {
            throw ValidationException::withMessages(['to' => ['A report covers up to five years, ending on or after it starts.']]);
        }

        return [(string) $from, $to];
    }
}
