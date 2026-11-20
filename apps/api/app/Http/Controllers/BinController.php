<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 4 (098): bins, the places inside a warehouse where stock is kept. Each stock item can be given its bin, one
 * per warehouse, so a count is taken one bin at a time (StockController::count). The quantity on hand stays the
 * warehouse's. Bins are archived, never deleted, and only once empty. Every query is the company's own under
 * row-level security, and no text from a request becomes SQL.
 */
final class BinController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit)
    {
    }

    /** Every bin of the company, by warehouse and code, with the items kept in it. */
    public function index(): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $items = DB::table('item_bins as ib')->join('items as i', 'i.id', '=', 'ib.item_id')->where('ib.company_id', $this->current->id())
            ->orderBy('i.name')->get(['ib.bin_id', 'i.id', 'i.name', 'i.sku', 'i.unit'])->groupBy('bin_id');
        $bins = DB::table('bins as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where('b.company_id', $this->current->id())
            ->orderBy('w.name')->orderByDesc('b.is_active')->orderBy('b.code')->limit(2000)
            ->get(['b.id', 'b.warehouse_id', 'w.name as warehouse', 'b.code', 'b.name', 'b.is_active']);

        return response()->json([
            'data' => $bins->map(fn ($b) => [...$this->present($b), 'items' => ($items[$b->id] ?? collect())
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'unit' => $i->unit])->values()->all()])->values(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('inventory.items')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])   // the company's own (rule 4)
            ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);
        $code = $this->code($data['code'], (string) $warehouse->getKey());
        $id = (string) Str::uuid7();
        $row = ['code' => $code, 'name' => self::blank($data['name'] ?? null)];
        DB::table('bins')->insert(['id' => $id, 'company_id' => $this->current->id(), 'warehouse_id' => $warehouse->getKey(), ...$row]);
        $this->audit->record($this->current->id(), 'created', 'bin', $id, null, ['warehouse_id' => $warehouse->getKey(), ...$row], userId: $request->user()->getKey());

        return response()->json([...$this->present($this->bin($id)), 'items' => []], 201);
    }

    /** Rename a bin, or archive it (once empty) and bring it back. */
    public function update(Request $request, string $bin): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $b = $this->bin($bin);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $changes = [];
        if (array_key_exists('code', $data)) {
            $changes['code'] = $this->code($data['code'], (string) $b->warehouse_id, (string) $b->id);
        }
        if (array_key_exists('name', $data)) {
            $changes['name'] = self::blank($data['name']);
        }
        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
            if (! $changes['is_active'] && DB::table('item_bins')->where(['company_id' => $this->current->id(), 'bin_id' => $b->id])->exists()) {
                throw ValidationException::withMessages(['is_active' => ["Bin {$b->code} still has items kept in it: move them to another bin first."]]);
            }
        }
        if ($changes !== []) {
            DB::table('bins')->where(['company_id' => $this->current->id(), 'id' => $b->id])->update([...$changes, 'updated_at' => now()]);
            $this->audit->record($this->current->id(), 'updated', 'bin', $b->id, ['code' => $b->code, 'name' => $b->name, 'is_active' => (bool) $b->is_active], $changes,
                userId: $request->user()->getKey());
        }

        return response()->json([...$this->present($this->bin($bin)), 'items' => $this->kept($bin)]);
    }

    /**
     * The items kept in a bin: those listed are kept here from now on (out of any other bin of the warehouse), those
     * no longer listed are kept in none.
     */
    public function setItems(Request $request, string $bin): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $b = $this->bin($bin);
        $data = $request->validate(['item_ids' => ['present', 'array', 'max:1000'], 'item_ids.*' => ['uuid', 'distinct']]);
        if (! $b->is_active) {
            throw ValidationException::withMessages(['item_ids' => ["Bin {$b->code} is archived: bring it back first."]]);
        }
        $items = Item::query()->whereIn('id', $data['item_ids'])->get()->keyBy(fn (Item $i) => (string) $i->getKey());   // the company's own (rule 4)
        foreach ($data['item_ids'] as $i => $id) {
            if (! isset($items[$id]) || ! $items[$id]->isStock()) {
                throw ValidationException::withMessages(["item_ids.{$i}" => ['Each is a stock item of this company.']]);
            }
        }
        $companyId = $this->current->id();

        return DB::transaction(function () use ($b, $items, $companyId, $request, $bin) {
            $before = DB::table('item_bins')->where(['company_id' => $companyId, 'bin_id' => $b->id])->pluck('item_id')->map(fn ($id) => (string) $id)->all();
            $after = array_keys($items->all());
            DB::table('item_bins')->where(['company_id' => $companyId, 'bin_id' => $b->id])->whereNotIn('item_id', $after)->delete();
            foreach ($after as $itemId) {
                $placed = DB::table('item_bins')->where(['company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $b->warehouse_id])->first(['id', 'bin_id']);
                if ($placed === null) {
                    DB::table('item_bins')->insert(['id' => (string) Str::uuid7(), 'company_id' => $companyId, 'item_id' => $itemId, 'warehouse_id' => $b->warehouse_id, 'bin_id' => $b->id]);
                } elseif ((string) $placed->bin_id !== (string) $b->id) {
                    DB::table('item_bins')->where(['company_id' => $companyId, 'id' => $placed->id])->update(['bin_id' => $b->id]);
                }
            }
            sort($before);
            sort($after);
            if ($before !== $after) {
                $this->audit->record($companyId, 'bin_items_set', 'bin', $b->id, ['item_ids' => $before], ['item_ids' => $after], userId: $request->user()->getKey());
            }

            return response()->json([...$this->present($this->bin($bin)), 'items' => $this->kept($bin)]);
        });
    }

    private function bin(string $id): object
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        return DB::table('bins as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where(['b.company_id' => $this->current->id(), 'b.id' => $id])
            ->first(['b.id', 'b.warehouse_id', 'w.name as warehouse', 'b.code', 'b.name', 'b.is_active']) ?? abort(404);
    }

    /** @return list<array{id: string, name: string, sku: ?string, unit: string}> */
    private function kept(string $bin): array
    {
        return DB::table('item_bins as ib')->join('items as i', 'i.id', '=', 'ib.item_id')->where(['ib.company_id' => $this->current->id(), 'ib.bin_id' => $bin])
            ->orderBy('i.name')->get(['i.id', 'i.name', 'i.sku', 'i.unit'])->map(fn ($i) => (array) $i)->all();
    }

    /** @return array<string, mixed> */
    private function present(object $b): array
    {
        return ['id' => $b->id, 'warehouse_id' => $b->warehouse_id, 'warehouse' => $b->warehouse, 'code' => $b->code, 'name' => $b->name, 'is_active' => (bool) $b->is_active];
    }

    /** A bin's code, trimmed, once in its warehouse whatever its case (compared here, so no text from the request becomes SQL). */
    private function code(string $code, string $warehouseId, ?string $except = null): string
    {
        $code = trim($code);
        if ($code === '') {
            throw ValidationException::withMessages(['code' => ['Give the bin a code.']]);
        }
        $taken = DB::table('bins')->where(['company_id' => $this->current->id(), 'warehouse_id' => $warehouseId])
            ->when($except !== null, fn ($q) => $q->where('id', '<>', $except))->pluck('code')
            ->contains(fn ($c) => mb_strtoupper(trim((string) $c)) === mb_strtoupper($code));
        if ($taken) {
            throw ValidationException::withMessages(['code' => ["This warehouse has a bin {$code} already."]]);
        }

        return $code;
    }

    private static function blank(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }
}
