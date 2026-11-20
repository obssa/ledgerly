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
        'authority_item_code', 'reorder_point', 'is_active', 'costing', 'tracking', 'max_level', 'preferred_supplier_id', 'barcode'];

    /** 085: 4 to 64 printable characters, no space at either end (the database checks the same). */
    private const BARCODE = ['nullable', 'string', 'regex:/^[!-~][ -~]{2,62}[!-~]$/'];

    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request, Reservations $reservations): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['inventory', 'non_inventory', 'service', 'bundle'])],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $items = Item::query()
            ->when(! ($q['include_archived'] ?? false), fn ($b) => $b->where('is_active', true))
            ->when($q['type'] ?? null, fn ($b, $t) => $b->where('type', $t))
            // A bound value with the query builder's ilike; the search text never becomes SQL (CLAUDE.md rule 1).
            ->when($q['search'] ?? null, fn ($b, $s) => $b->where(fn ($w) => $w
                ->where('name', 'ilike', '%' . addcslashes($s, '%_\\') . '%')->orWhere('sku', $s)->orWhere('barcode', $s)
                ->orWhereIn('id', DB::table('item_units')->where('barcode', $s)->select('item_id'))))
            ->orderBy('name')->limit(500)->get();

        $onHand = StockBalance::query()->whereIn('item_id', $items->pluck('id'))
            ->groupBy('item_id')->selectRaw('item_id, sum(quantity)::numeric(18,2)::text AS quantity, sum(value)::numeric(20,2)::text AS value')
            ->get()->keyBy('item_id');

        // 072: the other units each item is bought or sold in.
        $units = DB::table('item_units')->whereIn('item_id', $items->pluck('id'))->where('is_active', true)->orderBy('factor')
            ->get(['id', 'item_id', 'name', 'factor', 'barcode'])->groupBy('item_id');

        // 067: what the open sales orders still want of each stock item.
        $stock = $reservations->byItem($items->where('type', 'inventory')->pluck('id')->map(fn ($id) => (string) $id)->values()->all());

        return response()->json([
            'data' => $items->map(fn (Item $i) => [...$i->toArray(),
                'on_hand' => $onHand[$i->getKey()]->quantity ?? '0.00', 'value' => $onHand[$i->getKey()]->value ?? '0.00',
                'reserved' => $stock[(string) $i->getKey()]['reserved'] ?? '0.00', 'available' => $stock[(string) $i->getKey()]['available'] ?? null,
                'on_order' => $stock[(string) $i->getKey()]['on_order'] ?? '0.00',
                'units' => ($units[$i->getKey()] ?? collect())->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'factor' => (string) $u->factor, 'barcode' => $u->barcode])->values()]),
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
        if (isset($data['tracking']) && $data['tracking'] !== $item->tracking && $moved) {
            throw ValidationException::withMessages(['tracking' => ['This item has stock moves: its tracking stays. Archive it and add a new item to track it by batch.']]);
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
            'type' => [$req, Rule::in(['inventory', 'non_inventory', 'service', 'bundle'])],   // a bundle: sold as one, made of other items (073)
            'unit' => ['sometimes', 'string', 'max:20'],
            'sale_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'purchase_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            // The company's own accounts (row-level security), of the right kind.
            'income_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where('type', 'income')],
            'expense_account_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->whereIn('type', ['expense', 'asset']))],
            'authority_item_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'reorder_point' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            // 075: what a suggested order brings it back up to, and who it goes to (one of the company's suppliers).
            'max_level' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'preferred_supplier_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('contacts', 'id')->where('is_vendor', true)],
            'is_active' => [$item === null ? 'prohibited' : 'sometimes', 'boolean'],
            // Weighted average by default; FIFO, for a stock item, before its first move (064).
            'costing' => ['sometimes', Rule::in(['average', 'fifo'])],
            // Tracked by batch (074), for a stock item, before its first move.
            'tracking' => ['sometimes', Rule::in(['none', 'batch'])],
            'barcode' => ['sometimes', ...self::BARCODE],   // 085: the barcode printed on it
        ], ['costing.in' => 'Weighted average or FIFO.', 'barcode.regex' => 'A barcode is 4 to 64 letters, digits or symbols.']);
        if (($data['costing'] ?? 'average') === 'fifo' && ($data['type'] ?? $item?->type) !== 'inventory') {
            throw ValidationException::withMessages(['costing' => ['Only a stock item is costed FIFO.']]);
        }
        $min = array_key_exists('reorder_point', $data) ? $data['reorder_point'] : $item?->reorder_point;
        $max = array_key_exists('max_level', $data) ? $data['max_level'] : $item?->max_level;
        if ($min !== null && $max !== null && bccomp((string) $max, (string) $min, 2) < 0) {
            throw ValidationException::withMessages(['max_level' => ['The maximum level is at least the reorder point.']]);
        }
        if (($data['tracking'] ?? 'none') === 'batch' && ($data['type'] ?? $item?->type) !== 'inventory') {
            throw ValidationException::withMessages(['tracking' => ['Only a stock item is tracked by batch.']]);
        }
        if (! empty($data['barcode'])) {
            $this->assertBarcodeFree((string) $data['barcode'], $item?->getKey(), null);
        }

        return $data;
    }

    /** 085: within the company a barcode names one item or one unit (the database refuses a second one too). */
    private function assertBarcodeFree(string $code, ?string $itemId, ?string $unitId): void
    {
        $item = DB::table('items')->where('barcode', $code)->when($itemId, fn ($q) => $q->where('id', '<>', $itemId))->value('name');
        $unit = DB::table('item_units as u')->join('items as i', 'i.id', '=', 'u.item_id')->where('u.barcode', $code)
            ->when($unitId, fn ($q) => $q->where('u.id', '<>', $unitId))->first(['i.name', 'u.name as unit']);
        if ($item !== null || $unit !== null) {
            $whose = $item ?? "{$unit->name} ({$unit->unit})";
            throw ValidationException::withMessages(['barcode' => ["Barcode {$code} is already on {$whose}."]]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Item $item): array
    {
        // 090: where it is kept in each warehouse, beside what is there.
        $bins = DB::table('item_locations')->where('item_id', $item->getKey())->pluck('bin', 'warehouse_id');
        $balances = StockBalance::query()->where('item_id', $item->getKey())->with('warehouse:id,name')->get()
            ->map(fn (StockBalance $b) => ['warehouse' => $b->warehouse, 'quantity' => (string) $b->quantity, 'value' => (string) $b->value,
                'bin' => $bins[$b->warehouse_id] ?? null]);
        $locations = DB::table('item_locations as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->where('l.item_id', $item->getKey())
            ->orderBy('w.name')->get(['l.warehouse_id', 'w.name as warehouse', 'l.bin']);

        $units = DB::table('item_units')->where('item_id', $item->getKey())->orderByDesc('is_active')->orderBy('factor')
            ->get(['id', 'name', 'factor', 'is_active', 'created_at', 'barcode']);

        // 073: a bundle's parts, and whether they can still change (not once the bundle is on a document).
        $components = $item->type === 'bundle'
            ? DB::table('item_components as c')->join('items as i', 'i.id', '=', 'c.component_id')->where('c.bundle_id', $item->getKey())
                ->orderBy('i.name')->get(['c.component_id as item_id', 'i.name', 'i.type', 'i.unit', 'c.quantity'])
            : null;
        $used = $item->type === 'bundle' && DB::table('document_lines')->where('item_id', $item->getKey())->exists();

        // 074: its batches with what is on hand of each, the first to expire first.
        $batches = $item->tracking === 'batch'
            ? DB::table('stock_batches as b')->leftJoin('batch_balances as bb', 'bb.batch_id', '=', 'b.id')->leftJoin('warehouses as w', 'w.id', '=', 'bb.warehouse_id')
                ->where('b.item_id', $item->getKey())->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('b.number')
                ->get(['b.id', 'b.number', 'b.expires_on', 'w.name as warehouse', 'bb.quantity'])
            : null;

        // 089: its variants (each an item of its own), or the item it is a variant of.
        $variants = $item->parent_item_id === null
            ? Item::query()->where('parent_item_id', $item->getKey())->orderBy('variant_label')->get(['id', 'name', 'sku', 'barcode', 'variant_label', 'sale_price', 'is_active'])
            : [];
        $parent = $item->parent_item_id !== null ? Item::query()->whereKey($item->parent_item_id)->first(['id', 'name']) : null;

        return [...$item->toArray(), 'balances' => $balances, 'units' => $units, 'components' => $components, 'batches' => $batches,
            'variants' => $variants, 'parent' => $parent, 'locations' => $item->type === 'inventory' ? $locations : [],
            'can' => [...$this->can(), 'components' => $item->type === 'bundle' && ! $used && (bool) $this->current->membership()?->can('inventory.items'),
                'variants' => $item->parent_item_id === null && $item->type !== 'bundle' && (bool) $this->current->membership()?->can('inventory.items')]];
    }

    /**
     * 073: sets a bundle's parts, the whole list at once: other items of the company (never a bundle), each with its
     * quantity per bundle in its own unit. Refused once the bundle is on a document: archive it and make a new one.
     */
    /**
     * 089: makes the variants of an item from its options (Size: S, M, L; Colour: Red, Blue): one item for each
     * combination not made yet, of the item's type, unit, prices and accounts, named "Shirt — M / Red". Each is then an
     * item of its own (its stock, SKU, barcode, price). At most 100 at a time.
     */
    public function makeVariants(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'options' => ['required', 'array', 'min:1', 'max:3'],
            'options.*.name' => ['required', 'string', 'max:40'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.values.*' => ['required', 'string', 'max:40'],
        ]);
        if ($item->parent_item_id !== null || $item->type === 'bundle') {
            throw ValidationException::withMessages(['options' => ["{$item->name} is " . ($item->type === 'bundle' ? 'a bundle' : 'a variant itself') . ': variants are made of a plain item.']]);
        }
        $labels = [''];
        foreach ($data['options'] as $o) {
            $next = [];
            foreach ($labels as $l) {
                foreach (array_unique(array_map('trim', $o['values'])) as $v) {
                    $next[] = $l === '' ? trim($v) : "{$l} / " . trim($v);
                }
            }
            $labels = $next;
        }
        if (count($labels) > 100) {
            throw ValidationException::withMessages(['options' => ['At most 100 variants at a time: ' . count($labels) . ' would be made.']]);
        }
        $have = Item::query()->where('parent_item_id', $item->getKey())->pluck('variant_label')->map(fn ($l) => mb_strtolower(trim((string) $l)))->all();
        $made = [];
        DB::transaction(function () use ($item, $labels, $have, $request, &$made) {
            foreach ($labels as $label) {
                if (in_array(mb_strtolower($label), $have, true)) {
                    continue;
                }
                $variant = new Item(['name' => mb_substr("{$item->name} — {$label}", 0, 200), 'type' => $item->type, 'unit' => $item->unit,
                    'sale_price' => $item->sale_price, 'purchase_price' => $item->purchase_price, 'income_account_id' => $item->income_account_id,
                    'expense_account_id' => $item->expense_account_id, 'costing' => $item->costing, 'tracking' => $item->tracking]);
                $variant->forceFill(['company_id' => $item->company_id, 'parent_item_id' => $item->getKey(), 'variant_label' => $label])->save();
                $made[] = ['id' => $variant->getKey(), 'name' => $variant->name, 'variant_label' => $label];
            }
            $this->audit->record($this->current->id(), 'variants_made', 'item', $item->getKey(), null,
                ['variants' => array_column($made, 'variant_label')], userId: $request->user()->getKey());
        });

        return response()->json(['made' => $made, 'item' => $this->present($item->refresh())], 201);
    }

    /** 090: where a stock item is kept in a warehouse ("Aisle 3 / B2"); none clears it. */
    public function setLocation(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate(['warehouse_id' => ['required', 'uuid'], 'bin' => ['nullable', 'string', 'max:40']]);
        if ($item->type !== 'inventory') {
            throw ValidationException::withMessages(['bin' => ["{$item->name} is not a stock item: only stock is kept in a bin."]]);
        }
        // Company-scoped: another company's warehouse is simply not found.
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->find($data['warehouse_id'])
            ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose a warehouse of this company.']]);
        $bin = trim((string) ($data['bin'] ?? ''));
        $before = DB::table('item_locations')->where(['item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey()])->value('bin');
        if ($bin === '') {
            DB::table('item_locations')->where(['item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey()])->delete();
        } else {
            DB::table('item_locations')->upsert([['company_id' => $item->company_id, 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey(), 'bin' => $bin]],
                ['company_id', 'item_id', 'warehouse_id'], ['bin']);
        }
        if ($before !== ($bin === '' ? null : $bin)) {
            $this->audit->record($this->current->id(), 'bin_location_set', 'item', $item->getKey(), ['warehouse' => $warehouse->name, 'bin' => $before],
                ['warehouse' => $warehouse->name, 'bin' => $bin === '' ? null : $bin], userId: $request->user()->getKey());
        }

        return response()->json($this->present($item->refresh()));
    }

    public function setComponents(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'components' => ['present', 'array', 'max:50'],
            'components.*.item_id' => ['required', 'uuid', 'distinct', Rule::exists('items', 'id')->where(fn (Builder $q) => $q->where('type', '<>', 'bundle'))],
            'components.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
        ], ['components.*.item_id.exists' => 'A part is another of the company\'s items, not a bundle.']);
        if ($item->type !== 'bundle') {
            throw ValidationException::withMessages(['components' => ["{$item->name} is not a bundle: only a bundle is made of parts."]]);
        }
        if (in_array($item->getKey(), array_column($data['components'], 'item_id'), true)) {
            throw ValidationException::withMessages(['components' => ['A bundle is not a part of itself.']]);
        }
        if (DB::table('document_lines')->where('item_id', $item->getKey())->exists()) {
            throw ValidationException::withMessages(['components' => ["{$item->name} is on documents already: its parts stay as they were. Archive it and make a new bundle."]]);
        }
        $before = DB::table('item_components')->where('bundle_id', $item->getKey())->pluck('quantity', 'component_id')->map(fn ($q) => (string) $q)->all();
        DB::transaction(function () use ($item, $data) {
            DB::table('item_components')->where('bundle_id', $item->getKey())->delete();
            foreach ($data['components'] as $c) {
                DB::table('item_components')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'company_id' => $item->company_id,
                    'bundle_id' => $item->getKey(), 'component_id' => $c['item_id'], 'quantity' => (string) $c['quantity']]);
            }
        });
        $after = collect($data['components'])->mapWithKeys(fn (array $c) => [$c['item_id'] => (string) $c['quantity']])->all();
        $this->audit->record($this->current->id(), 'components_set', 'item', $item->getKey(), ['components' => $before], ['components' => $after],
            userId: $request->user()->getKey());

        return response()->json($this->present($item->refresh()));
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
            'barcode' => self::BARCODE,   // 085: the barcode of this unit (a carton's own)
        ], ['factor.gt' => 'How many of the item\'s own unit are in one: above zero.', 'barcode.regex' => 'A barcode is 4 to 64 letters, digits or symbols.']);
        if (! empty($data['barcode'])) {
            $this->assertBarcodeFree((string) $data['barcode'], null, null);
        }
        $name = trim($data['name']);
        if (mb_strtolower($name) === mb_strtolower(trim((string) $item->unit))) {
            throw ValidationException::withMessages(['name' => ["{$item->name} is already counted in {$item->unit}: a unit is another one."]]);
        }
        if (DB::table('item_units')->where('item_id', $item->getKey())->whereRaw('lower(btrim(name)) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => ["{$item->name} already has a unit called {$name}."]]);
        }
        $id = (string) \Illuminate\Support\Str::uuid7();
        DB::table('item_units')->insert(['id' => $id, 'company_id' => $item->company_id, 'item_id' => $item->getKey(), 'name' => $name,
            'factor' => (string) $data['factor'], 'created_by' => $request->user()->getKey(), 'barcode' => $data['barcode'] ?? null]);
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
