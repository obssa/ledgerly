<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Models\Dimension;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 1, locations and dimensions: every member reads them (they are picked on transactions); an accountant,
 * owner or admin adds, renames and turns them off (accounting.dimensions). The code never changes; a dimension is
 * never deleted, so old entries keep their meaning.
 */
final class DimensionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('company.view');
        $kind = $request->validate(['kind' => ['nullable', Rule::in(Dimension::KINDS)]])['kind'] ?? null;

        return response()->json(['data' => Dimension::query()
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('kind')->orderBy('code')
            ->get(['id', 'kind', 'code', 'name', 'is_active'])]);
    }

    public function store(Request $request, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('accounting.dimensions');

        $data = $request->validate([
            'kind' => ['required', Rule::in(Dimension::KINDS)],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,20}$/',
                Rule::unique('dimensions', 'code')->where('company_id', $current->id())->where('kind', $request->input('kind'))],
            'name' => ['required', 'string', 'max:120'],
        ], ['code.regex' => 'Up to 20 letters, digits and . _ -', 'code.unique' => 'This code is already used.']);

        $dimension = Dimension::query()->create($data);
        $this->audit->record($current->id(), 'created', 'dimension', $dimension->getKey(), null, $data, userId: $request->user()->getKey());

        return response()->json($dimension->refresh()->only(['id', 'kind', 'code', 'name', 'is_active']), 201);
    }

    /** Rename or turn on/off. The kind and code never change. */
    public function update(Request $request, Dimension $dimension, CurrentCompany $current): JsonResponse
    {
        $this->authorizeCompany('accounting.dimensions');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $before = $dimension->only(['name', 'is_active']);
        $dimension->fill($data);
        $changes = AuditLogger::diff($before, $dimension->only(['name', 'is_active']));
        if ($changes !== []) {
            $dimension->save();
            $this->audit->record($current->id(), 'updated', 'dimension', $dimension->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes),
                userId: $request->user()->getKey());
        }

        return response()->json($dimension->only(['id', 'kind', 'code', 'name', 'is_active']));
    }
}
