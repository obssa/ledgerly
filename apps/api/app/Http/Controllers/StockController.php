<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 4, stock on hand and adjustments. An adjustment (a count difference, damage, a write-off, or the opening stock
 * when the company starts) moves stock with a reason and posts its value in the same transaction:
 *   more:  Dr Inventory, Cr Inventory adjustments (Cr Opening balance equity for opening stock)
 *   less:  Dr Inventory adjustments, Cr Inventory, at the weighted average the database decides
 */
final class StockController extends Controller
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /** What is on hand, per item and warehouse, with its value (base currency). */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $q = $request->validate(['warehouse_id' => ['nullable', 'uuid']]);

        $base = $this->current->get()->base_currency;
        $rows = StockBalance::query()->with(['item:id,sku,name,unit,reorder_point', 'warehouse:id,name'])
            ->when($q['warehouse_id'] ?? null, fn ($b, $id) => $b->where('warehouse_id', $id))
            ->where('quantity', '<>', 0)->get()
            ->sortBy(fn (StockBalance $b) => [$b->item?->name, $b->warehouse?->name])->values()
            ->map(fn (StockBalance $b) => ['item' => $b->item, 'warehouse' => $b->warehouse, 'quantity' => (string) $b->quantity, 'value' => (string) $b->value,
                'average_cost' => Money::of((string) $b->value, $base)->dividedBy((string) $b->quantity)->roundToCurrency()->toDecimalString()]);
        $total = $rows->reduce(fn (Money $sum, array $r) => $sum->plus(Money::of($r['value'], $base)), Money::zero($base));

        return response()->json(['data' => $rows, 'total_value' => $total->toDecimalString()]);
    }

    public function adjust(Request $request, StockLedger $stock, PostingService $posting, AuditLogger $audit): JsonResponse
    {
        $this->authorizeCompany('inventory.adjust');
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'quantity' => ['required', 'decimal:0,2', 'not_in:0'],
            'unit_cost' => ['required_if:opening,true', 'nullable', 'decimal:0,6', 'min:0'],   // for stock coming in
            'opening' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $opening = (bool) ($data['opening'] ?? false);
        $in = ! str_starts_with(trim((string) $data['quantity']), '-');   // not zero (validated): in or out
        if ($in && ! isset($data['unit_cost'])) {
            throw ValidationException::withMessages(['unit_cost' => ['Stock coming in needs its unit cost.']]);
        }
        if ($opening && ! $in) {
            throw ValidationException::withMessages(['quantity' => ['Opening stock is a quantity on hand: more than zero.']]);
        }

        return DB::transaction(function () use ($data, $stock, $posting, $audit, $request, $opening, $in) {
            $company = $this->current->get();
            // Loaded through the company-scoped models (CLAUDE.md rule 4).
            $item = Item::query()->where('is_active', true)->find($data['item_id']);
            if ($item === null || ! $item->isStock()) {
                throw ValidationException::withMessages(['item_id' => ['Choose an active stock item of this company.']]);
            }
            $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])
                ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);
            $date = new DateTimeImmutable($data['date']);

            $move = $stock->move($company->getKey(), $company->base_currency, $item->getKey(), $warehouse->getKey(), $date,
                $opening ? 'opening' : 'adjustment', (string) $data['quantity'], (string) ($data['unit_cost'] ?? '0'), $data['reason'], null, $request->user()->getKey());

            $value = $in ? $move['amount'] : $move['amount']->negated();
            $entry = null;
            if (! $value->isZero()) {
                $draft = new JournalDraft($company->getKey(), $date, $company->base_currency, $company->base_currency, '1',
                    ($opening ? 'Opening stock: ' : 'Stock adjustment: ') . "{$item->name}, {$warehouse->name} ({$data['reason']})", 'stock_adjustment', $move['id']);
                $inventory = Account::system('inventory')->getKey();
                $other = Account::system($opening ? 'opening_balance_equity' : 'inventory_adjustment')->getKey();
                $draft->add($in ? JournalLineDraft::debit($inventory, $value) : JournalLineDraft::credit($inventory, $value));
                $draft->add($in ? JournalLineDraft::credit($other, $value) : JournalLineDraft::debit($other, $value));
                $entry = $posting->post($draft, $request->user()->getKey());
            }

            $audit->record($company->getKey(), $opening ? 'opening_stock' : 'stock_adjusted', 'item', $item->getKey(), null, [
                'warehouse' => $warehouse->name, 'quantity' => (string) $data['quantity'], 'value' => $move['amount']->toDecimalString(),
                'reason' => $data['reason'], 'journal_entry' => $entry?->number,
            ], $data['reason'], $request->user()->getKey());

            return response()->json(['move_id' => $move['id'], 'amount' => $move['amount']->toDecimalString(), 'unit_cost' => $move['unit_cost'],
                'journal_entry_id' => $entry?->getKey()], 201);
        });
    }
}
