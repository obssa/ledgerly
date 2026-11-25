<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Reports\StockReports;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Module 4 and 10: stock movement for a period, and stock ageing (slow-moving and dead stock) at a date. */
final class StockReportController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly StockReports $reports)
    {
    }

    public function movement(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'warehouse_id' => ['nullable', 'uuid']]);
        $warehouse = $this->warehouse($q['warehouse_id'] ?? null);
        $to = $q['to'] ?? $this->current->today();
        $from = $q['from'] ?? (DB::table('fiscal_years')->where('company_id', $this->current->id())->where('starts_on', '<=', $to)->where('ends_on', '>=', $to)->value('starts_on')
            ?? (new \DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d'));
        // Any span, "All dates" included (the web app sends the widest dates for an open end): it ends on or after it starts.
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['A report ends on or after it starts.']]);
        }

        return response()->json(['from' => (string) $from, 'to' => $to, 'warehouse' => $warehouse, 'currency' => $this->current->get()->base_currency,
            ...$this->reports->movement($this->current->id(), (string) $from, $to, $warehouse['id'] ?? null)]);
    }

    public function ageing(Request $request): JsonResponse
    {
        $this->authorizeCompany('reports.view');
        $q = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'warehouse_id' => ['nullable', 'uuid']]);
        $warehouse = $this->warehouse($q['warehouse_id'] ?? null);
        $asOf = $q['as_of'] ?? $this->current->today();

        return response()->json(['as_of' => $asOf, 'warehouse' => $warehouse, 'currency' => $this->current->get()->base_currency,
            ...$this->reports->ageing($this->current->id(), $asOf, $warehouse['id'] ?? null)]);
    }

    /** @return array{id: string, name: string}|null a warehouse of this company, loaded through its scoped model (CLAUDE.md rule 4) */
    private function warehouse(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $w = Warehouse::query()->find($id) ?? throw ValidationException::withMessages(['warehouse_id' => ['Not a warehouse of this company.']]);

        return ['id' => $w->getKey(), 'name' => $w->name];
    }
}
