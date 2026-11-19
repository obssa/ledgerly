<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Assemblies;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 4: assemblies (087). Seeing an item's parts and builds takes inventory.view; setting its parts takes
 * inventory.items; building takes inventory.transfer (stock moves between items at cost, with no entry, as a transfer
 * moves it between warehouses). Items and warehouses are loaded through the company-scoped models.
 */
final class AssemblyController extends Controller
{
    public function __construct(private readonly Assemblies $assemblies, private readonly CurrentCompany $current)
    {
    }

    public function show(Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $m = $this->current->membership();

        return response()->json(['parts' => $this->assemblies->parts($item), 'builds' => $this->assemblies->builds($item),
            'can' => ['set_parts' => (bool) $m?->can('inventory.items'), 'build' => (bool) $m?->can('inventory.transfer')]]);
    }

    public function setParts(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'parts' => ['present', 'array', 'max:100'],
            'parts.*.item_id' => ['required', 'uuid', 'distinct'],
            'parts.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:1000000'],
        ]);

        return response()->json(['parts' => $this->assemblies->setParts($item, $data['parts'], $request->user()->getKey())]);
    }

    public function build(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.transfer');
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'built_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])
            ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);

        return response()->json($this->assemblies->build($item, $warehouse, (string) $data['quantity'], $data['built_on'] ?? $this->current->today(),
            $data['reference'] ?? null, $request->user()->getKey()), 201);
    }
}
