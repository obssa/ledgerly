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

    /** The report put together by the reader (CustomReport): rows, columns, figure and filters from fixed lists. */
    public function custom(Request $request, \App\Domain\Reports\CustomReport $report): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $o = $request->validate([
            'rows' => ['nullable', Rule::in(\App\Domain\Reports\CustomReport::ROWS)],
            'columns' => ['nullable', Rule::in(\App\Domain\Reports\CustomReport::COLUMNS)],
            'measure' => ['nullable', Rule::in(\App\Domain\Reports\CustomReport::MEASURES)],
            'account_type' => ['nullable', Rule::in(\App\Domain\Reports\CustomReport::ACCOUNT_TYPES)],
            'project_id' => ['nullable', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'class_id' => ['nullable', 'uuid'],
        ]);
        [$from, $to] = $this->period($request);
        $options = ['rows' => $o['rows'] ?? 'account', 'columns' => $o['columns'] ?? 'total', 'measure' => $o['measure'] ?? 'natural',
            'account_type' => $o['account_type'] ?? null];
        // A dimension is the company's own, or the filter finds nothing: a request cannot read another company's.
        foreach (['project' => 'project', 'location' => 'location', 'class' => 'class'] as $name => $kind) {
            $id = $o["{$name}_id"] ?? null;
            if ($id !== null && ! DB::table('dimensions')->where(['company_id' => $this->current->id(), 'id' => $id, 'kind' => $kind])->exists()) {
                throw ValidationException::withMessages(["{$name}_id" => ["No such {$kind} in this company."]]);
            }
            $options["{$name}_id"] = $id;
        }

        return response()->json(['from' => $from, 'to' => $to, 'currency' => $this->current->get()->base_currency, 'options' => $options,
            ...$report->run($this->current->id(), $from, $to, $options)]);
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
        $groups = $side === 'sales' ? AnalysisReports::GROUPS : array_values(array_diff(AnalysisReports::GROUPS, ['salesperson']));
        $group = $request->validate(['group' => ['nullable', Rule::in($groups)]])['group'] ?? 'contact';
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
