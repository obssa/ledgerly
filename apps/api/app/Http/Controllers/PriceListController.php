<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Ledger\Currency;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: customer groups and price lists (080). A price list is a group of customers who pay its prices. Seeing
 * lists takes inventory.view; making and changing them, inventory.items. Putting a customer on one is part of editing
 * the customer (contacts.edit). Every read and write is inside the company's row-level security, and items are loaded
 * through the company-scoped model.
 */
final class PriceListController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $customers = DB::table('contacts')->whereNotNull('price_list_id')->where('is_active', true)->groupBy('price_list_id')
            ->select('price_list_id')->selectRaw('count(*) AS n')->pluck('n', 'price_list_id');
        $prices = DB::table('price_list_prices')->groupBy('price_list_id')->select('price_list_id')->selectRaw('count(*) AS n')->pluck('n', 'price_list_id');

        return response()->json([
            'data' => DB::table('price_lists')->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn ($l) => [...(array) $l, 'customers' => (int) ($customers[$l->id] ?? 0), 'items' => (int) ($prices[$l->id] ?? 0)])->all(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('inventory.items')],
        ]);
    }

    /** For the invoice, quote and order forms: each active list's currency and prices, and which list each customer is on. */
    public function prices(): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $lists = DB::table('price_lists')->where('is_active', true)->get(['id', 'currency']);
        $prices = DB::table('price_list_prices')->whereIn('price_list_id', $lists->pluck('id'))->get(['price_list_id', 'item_id', 'unit_price']);

        return response()->json(['data' => $lists->map(fn ($l) => ['id' => $l->id, 'currency' => $l->currency,
            'prices' => $prices->where('price_list_id', $l->id)->mapWithKeys(fn ($p) => [$p->item_id => (string) $p->unit_price])->all() ?: (object) []])->values()->all()]);
    }

    public function show(string $priceList): JsonResponse
    {
        $this->authorizeCompany('inventory.view');

        return response()->json($this->present($priceList));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'currency' => ['nullable', Rule::in(Currency::all())],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $id = (string) Str::uuid7();
        $row = ['id' => $id, 'company_id' => $this->current->id(), 'name' => trim($data['name']),
            'currency' => $data['currency'] ?? $this->current->get()->base_currency, 'notes' => $data['notes'] ?? null];
        $this->unique(fn () => DB::table('price_lists')->insert($row));
        $this->audit->record($this->current->id(), 'price_list_created', 'price_list', $id, null, array_diff_key($row, ['id' => 1, 'company_id' => 1]), userId: $request->user()->getKey());

        return response()->json($this->present($id), 201);
    }

    public function update(Request $request, string $priceList): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $before = $this->find($priceList);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'currency' => ['sometimes', 'required', Rule::in(Currency::all())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (($data['is_active'] ?? true) === false && DB::table('contacts')->where('price_list_id', $priceList)->exists()) {
            throw ValidationException::withMessages(['is_active' => ['Customers are still on this list: move them to another list (or none) first.']]);
        }
        if ($data !== []) {
            $this->unique(fn () => DB::table('price_lists')->where('id', $priceList)->update($data));
            $this->audit->record($this->current->id(), 'price_list_updated', 'price_list', $priceList,
                array_intersect_key((array) $before, $data), $data, userId: $request->user()->getKey());
        }

        return response()->json($this->present($priceList));
    }

    /** Sets the list's prices: the items given, at the prices given; items left out are taken off the list. */
    public function setPrices(Request $request, string $priceList): JsonResponse
    {
        $this->authorizeCompany('inventory.items');
        $this->find($priceList);
        $data = $request->validate([
            'prices' => ['present', 'array', 'max:2000'],
            'prices.*.item_id' => ['required', 'uuid', 'distinct'],
            'prices.*.unit_price' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999999999'],
        ]);
        $ids = array_column($data['prices'], 'item_id');
        // Company-scoped: another company's item is simply not found.
        if (Item::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['prices' => ['Price the company\'s own items.']]);
        }
        $before = DB::table('price_list_prices')->where('price_list_id', $priceList)->pluck('unit_price', 'item_id')->map(fn ($p) => (string) $p)->all();
        DB::transaction(function () use ($priceList, $data, $ids) {
            DB::table('price_list_prices')->where('price_list_id', $priceList)->whereNotIn('item_id', $ids)->delete();
            foreach ($data['prices'] as $p) {
                DB::table('price_list_prices')->upsert(
                    [['company_id' => $this->current->id(), 'price_list_id' => $priceList, 'item_id' => $p['item_id'], 'unit_price' => (string) $p['unit_price']]],
                    ['price_list_id', 'item_id'], ['unit_price'],
                );
            }
        });
        $after = collect($data['prices'])->mapWithKeys(fn ($p) => [$p['item_id'] => bcadd((string) $p['unit_price'], '0', 2)])->all();
        $this->audit->record($this->current->id(), 'price_list_prices_set', 'price_list', $priceList, ['prices' => $before], ['prices' => $after], userId: $request->user()->getKey());

        return response()->json($this->present($priceList));
    }

    /** @return array<string, mixed> */
    private function present(string $id): array
    {
        $list = $this->find($id);

        return [...(array) $list,
            'prices' => DB::table('price_list_prices as p')->join('items as i', 'i.id', '=', 'p.item_id')->where('p.price_list_id', $id)
                ->orderBy('i.name')->get(['p.item_id', 'i.name', 'i.sku', 'i.sale_price', 'p.unit_price'])->all(),
            'customers' => DB::table('contacts')->where('price_list_id', $id)->orderBy('name')->limit(500)->get(['id', 'name'])->all(),
            'can' => ['manage' => (bool) $this->current->membership()?->can('inventory.items')]];
    }

    private function find(string $id): object
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        return DB::table('price_lists')->where('id', $id)->first() ?? abort(404);
    }

    private function unique(callable $write): void
    {
        try {
            DB::transaction(fn () => $write());   // its own savepoint: a duplicate name leaves the request's transaction usable
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['name' => ['A price list of this name already exists.']]);
            }
            throw $e;
        }
    }
}
