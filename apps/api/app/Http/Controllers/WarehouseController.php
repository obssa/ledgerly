<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Module 4, warehouses: where stock is kept (warehouses, shops, vans). Archived, never deleted. */
final class WarehouseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('inventory.view');

        return response()->json(['data' => Warehouse::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('warehouses', 'name')->where('company_id', $this->current->id())],
        ]);
        $warehouse = Warehouse::query()->create($data);
        $this->audit->record($this->current->id(), 'created', 'warehouse', $warehouse->getKey(), null, $data, userId: $request->user()->getKey());

        return response()->json($warehouse->refresh(), 201);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('warehouses', 'name')->where('company_id', $this->current->id())->ignore($warehouse->getKey())],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $warehouse->only(['name', 'is_active']);
        $warehouse->fill($data)->save();
        $this->audit->record($this->current->id(), 'updated', 'warehouse', $warehouse->getKey(), $before, $warehouse->only(['name', 'is_active']), userId: $request->user()->getKey());

        return response()->json($warehouse->refresh());
    }
}
