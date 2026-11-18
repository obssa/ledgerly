<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Reservations;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 4, items: stock items (with a bin card per warehouse), non-stock items and services. Anyone may read them;
 * inventory.items adds and changes them. Archived, never deleted; an item with stock moves keeps its type.
 */
final class ItemController extends Controller
{
    private const FIELDS = ['sku', 'name', 'type', 'unit', 'sale_price', 'purchase_price', 'income_account_id', 'expense_account_id',
        'authority_item_code', 'reorder_point', 'is_active', 'costing'];

    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request, Reservations $reservations): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['inventory', 'non_inventory', 'service'])],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $items = Item::query()
            ->when(! ($q['include_archived'] ?? false), fn ($b) => $b->where('is_active', true))
            ->when($q['type'] ?? null, fn ($b, $t) => $b->where('type', $t))
            // A bound value with the query builder's ilike; the search text never becomes SQL (CLAUDE.md rule 1).
            ->when($q['search'] ?? null, fn ($b, $s) => $b->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . addcslashes($s, '%_\\') . '%')->orWhere('sku', $s)))
            ->orderBy('name')->limit(500)->get();

        $onHand = StockBalance::query()->whereIn('item_id', $items->pluck('id'))
            ->groupBy('item_id')->selectRaw('item_id, sum(quantity)::numeric(18,2)::text AS quantity, sum(value)::numeric(20,2)::text AS value')
            ->get()->keyBy('item_id');

        // 072: the other units each item is bought or sold in.
        $units = DB::table('item_units')->whereIn('item_id', $items->pluck('id'))->where('is_active', true)->orderBy('factor')
            ->get(['id', 'item_id', 'name', 'factor'])->groupBy('item_id');

        // 067: what the open sales orders still want of each stock item.
        $stock = $reservations->byItem($items->where('type', 'inventory')->pluck('id')->map(fn ($id) => (string) $id)->values()->all());

        return response()->json([
            'data' => $items->map(fn (Item $i) => [...$i->toArray(),
                'on_hand' => $onHand[$i->getKey()]->quantity ?? '0.00', 'value' => $onHand[$i->getKey()]->value ?? '0.00',
                'reserved' => $stock[(string) $i->getKey()]['reserved'] ?? '0.00', 'available' => $stock[(string) $i->getKey()]['available'] ?? null,
                'on_order' => $stock[(string) $i->getKey()]['on_order'] ?? '0.00',
                'units' => ($units[$i->getKey()] ?? collect())->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'factor' => (string) $u->factor])->values()]),
            'can' => $this->can(),
        ]);
    }

    public function show(Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.view');

        return response()->json($this->present($item));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $this->validated($request, null);
        $item = Item::query()->create($data);
        $this->audit->record($this->current->id(), 'created', 'item', $item->getKey(), null, $data, userId: $request->user()->getKey());

        return response()->json($this->present($item->refresh()), 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $this->validated($request, $item);
        $moved = DB::table('stock_moves')->where(['company_id' => $item->company_id, 'item_id' => $item->getKey()])->exists();
        if (isset($data['type']) && $data['type'] !== $item->type && $moved) {
            throw ValidationException::withMessages(['type' => ['This item has stock moves: its type stays. Archive it and add a new item instead.']]);
        }
        // FIFO or weighted average is chosen before the first stock move and kept (064).
        if (isset($data['costing']) && $data['costing'] !== $item->costing && $moved) {
            throw ValidationException::withMessages(['costing' => ['This item has stock moves: its costing stays. Archive it and add a new item to cost it another way.']]);
        }

        $before = $item->only(self::FIELDS);
        $item->fill($data);
        $changes = \App\Domain\Audit\AuditLogger::diff($before, $item->only(self::FIELDS));
        if ($changes !== []) {
            $item->save();
            $this->audit->record($this->current->id(), 'updated', 'item', $item->getKey(),
                array_map(fn (array $c) => $c['old'], $changes), array_map(fn (array $c) => $c['new'], $changes), userId: $request->user()->getKey());
        }

        return response()->json($this->present($item->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Item $item): array
    {
        $req = $item === null ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:200'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:60', Rule::unique('items', 'sku')->where('company_id', $this->current->id())->ignore($item?->getKey())],
            'type' => [$req, Rule::in(['inventory', 'non_inventory', 'service'])],
            'unit' => ['sometimes', 'string', 'max:20'],
            'sale_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'purchase_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            // The company's own accounts (row-level security), of the right kind.
            'income_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')],
            'expense_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->whereIn('type', ['expense', 'asset']))],
            'authority_item_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'reorder_point' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'is_active' => [$item === null ? 'prohibited' : 'sometimes', 'boolean'],
            // Weighted average by default; FIFO, for a stock item, before its first move (064).
            'costing' => ['sometimes', Rule::in(['average', 'fifo'])],
        ], ['costing.in' => 'Weighted average or FIFO.']);
        if (($data['costing'] ?? 'average') === 'fifo' && ($data['type'] ?? $item?->type) !== 'inventory') {
            throw ValidationException::withMessages(['costing' => ['Only a stock item is costed FIFO.']]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Item $item): array
    {
        $balances = StockBalance::query()->where('item_id', $item->getKey())->with('warehouse:id,name')->get()
            ->map(fn (StockBalance $b) => ['warehouse' => $b->warehouse, 'quantity' => (string) $b->quantity, 'value' => (string) $b->value]);

        $units = DB::table('item_units')->where('item_id', $item->getKey())->orderByDesc('is_active')->orderBy('factor')
            ->get(['id', 'name', 'factor', 'is_active', 'created_at']);

        return [...$item->toArray(), 'balances' => $balances, 'units' => $units, 'can' => $this->can()];
    }

    /**
     * 072: another unit the item is bought or sold in, a fixed number of its own: a carton of 12, a 25 kg sack. Its name
     * and size never change once made; it is archived instead.
     */
    public function addUnit(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'factor' => ['required', 'decimal:0,6', 'gt:0', 'max:1000000'],
        ], ['factor.gt' => 'How many of the item\'s own unit are in one: above zero.']);
        $name = trim($data['name']);
        if (mb_strtolower($name) === mb_strtolower(trim((string) $item->unit))) {
            throw ValidationException::withMessages(['name' => ["{$item->name} is already counted in {$item->unit}: a unit is another one."]]);
        }
        if (DB::table('item_units')->where('item_id', $item->getKey())->whereRaw('lower(btrim(name)) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => ["{$item->name} already has a unit called {$name}."]]);
        }
        $id = (string) \Illuminate\Support\Str::uuid7();
        DB::table('item_units')->insert(['id' => $id, 'company_id' => $item->company_id, 'item_id' => $item->getKey(), 'name' => $name,
            'factor' => (string) $data['factor'], 'created_by' => $request->user()->getKey()]);
        $this->audit->record($this->current->id(), 'unit_added', 'item', $item->getKey(), null,
            ['unit_id' => $id, 'name' => $name, 'factor' => (string) $data['factor']], userId: $request->user()->getKey());

        return response()->json($this->present($item->refresh()), 201);
    }

    /** 072: a unit no longer offered on new lines; the lines already in it keep it. */
    public function archiveUnit(Request $request, Item $item, string $unit): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $found = DB::table('item_units')->where(['id' => $unit, 'item_id' => $item->getKey()])->first(['id', 'name', 'is_active']);
        if ($found === null) {
            abort(404);
        }
        if ($found->is_active) {
            DB::table('item_units')->where('id', $unit)->update(['is_active' => false]);
            $this->audit->record($this->current->id(), 'unit_archived', 'item', $item->getKey(), ['unit_id' => $unit, 'name' => $found->name, 'is_active' => true],
                ['is_active' => false], userId: $request->user()->getKey());
        }

        return response()->json($this->present($item->refresh()));
    }

    private function can(): array
    {
        $m = $this->current->membership();

        return ['edit' => (bool) $m?->can('inventory.items'), 'adjust' => (bool) $m?->can('inventory.adjust')];
    }
}
