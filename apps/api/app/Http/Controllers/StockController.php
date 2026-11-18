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

    /**
     * Moves stock from one warehouse to another: out at the source's weighted average, in at exactly that value. Inventory
     * is one account for every warehouse, so nothing posts to the ledger.
     */
    public function transfer(Request $request, StockLedger $stock, AuditLogger $audit): JsonResponse
    {
        $this->authorizeCompany('inventory.transfer');
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'from_warehouse_id' => ['required', 'uuid'],
            'to_warehouse_id' => ['required', 'uuid', 'different:from_warehouse_id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        return DB::transaction(function () use ($data, $stock, $audit, $request) {
            $company = $this->current->get();
            $item = Item::query()->where('is_active', true)->find($data['item_id']);
            if ($item === null || ! $item->isStock()) {
                throw ValidationException::withMessages(['item_id' => ['Choose an active stock item of this company.']]);
            }
            $from = Warehouse::query()->find($data['from_warehouse_id'])
                ?? throw ValidationException::withMessages(['from_warehouse_id' => ['Not a warehouse of this company.']]);
            $to = Warehouse::query()->where('is_active', true)->find($data['to_warehouse_id'])
                ?? throw ValidationException::withMessages(['to_warehouse_id' => ['Choose an active warehouse of this company.']]);
            $date = new DateTimeImmutable($data['date']);
            $reference = $data['reference'] ?? "Transfer to {$to->name}";
            $group = (string) \Illuminate\Support\Str::uuid7();

            $out = $stock->move($company->getKey(), $company->base_currency, $item->getKey(), $from->getKey(), $date, 'transfer_out',
                '-' . $data['quantity'], '0', $reference, null, $request->user()->getKey(), null, $group);
            $in = $stock->move($company->getKey(), $company->base_currency, $item->getKey(), $to->getKey(), $date, 'transfer_in',
                (string) $data['quantity'], '0', $data['reference'] ?? "Transfer from {$from->name}", null, $request->user()->getKey(),
                $out['amount']->negated()->toDecimalString(), $group);

            $audit->record($company->getKey(), 'stock_transferred', 'item', $item->getKey(), null, [
                'from' => $from->name, 'to' => $to->name, 'quantity' => (string) $data['quantity'], 'value' => $in['amount']->toDecimalString(),
            ], userId: $request->user()->getKey());

            return response()->json(['transfer_group' => $group, 'value' => $in['amount']->toDecimalString(), 'unit_cost' => $in['unit_cost']], 201);
        });
    }

    /** Stock items at or below their reorder point, across all warehouses. */
    public function reorder(): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $items = Item::query()->where(['is_active' => true, 'type' => 'inventory'])->whereNotNull('reorder_point')->orderBy('name')->get();
        $onHand = StockBalance::query()->whereIn('item_id', $items->pluck('id'))->groupBy('item_id')
            ->selectRaw('item_id, sum(quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'item_id');
        $base = $this->current->get()->base_currency;

        return response()->json(['data' => $items->filter(fn (Item $i) => ! Money::of((string) $i->reorder_point, $base)->minus(Money::of((string) ($onHand[$i->getKey()] ?? '0'), $base))->isNegative())
            ->map(fn (Item $i) => ['item' => $i->only(['id', 'sku', 'name', 'unit', 'reorder_point', 'purchase_price']), 'on_hand' => (string) ($onHand[$i->getKey()] ?? '0.00')])->values()]);
    }

    /**
     * Module 9a: materials issued from a warehouse to a project (055). They leave stock at the warehouse's cost and post
     * to the cost account chosen, tagged with the project, so the project's profit counts them.
     */
    public function issue(Request $request, StockLedger $stock, PostingService $posting, AuditLogger $audit): JsonResponse
    {
        $this->authorizeCompany('inventory.adjust');
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'project_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return DB::transaction(function () use ($data, $stock, $posting, $audit, $request) {
            $company = $this->current->get();
            // Loaded through the company-scoped models and the company's own rows (CLAUDE.md rule 4).
            $item = Item::query()->where('is_active', true)->find($data['item_id']);
            if ($item === null || ! $item->isStock()) {
                throw ValidationException::withMessages(['item_id' => ['Choose an active stock item of this company.']]);
            }
            $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])
                ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);
            $project = DB::table('dimensions')->where(['company_id' => $company->getKey(), 'id' => $data['project_id'], 'kind' => 'project', 'is_active' => true])
                ->first(['id', 'code', 'name']) ?? throw ValidationException::withMessages(['project_id' => ['Choose an active project of this company.']]);
            $account = Account::query()->whereKey($data['account_id'])->where('type', 'expense')->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['account_id' => ['Choose an active cost account of this company.']]);
            $date = new DateTimeImmutable($data['date']);
            $reference = mb_substr($data['note'] ?? "Issued to {$project->code} {$project->name}", 0, 255);

            $move = $stock->move($company->getKey(), $company->base_currency, $item->getKey(), $warehouse->getKey(), $date, 'project_issue',
                '-' . $data['quantity'], '0', $reference, null, $request->user()->getKey(), null, null, $project->id);
            $value = $move['amount']->negated();   // a move out is valued negative
            $entry = null;
            if (! $value->isZero()) {
                $draft = new JournalDraft($company->getKey(), $date, $company->base_currency, $company->base_currency, '1',
                    "Issued to {$project->code}: {$item->name} from {$warehouse->name}", 'stock_issue', $move['id']);
                $draft->add(JournalLineDraft::debit($account->getKey(), $value, null, "{$item->name} for {$project->code}", null, ['project_id' => $project->id]));
                $draft->add(JournalLineDraft::credit(Account::system('inventory')->getKey(), $value, null, "{$item->name} for {$project->code}"));
                $entry = $posting->post($draft, $request->user()->getKey());
            }
            $audit->record($company->getKey(), 'stock_issued_to_project', 'item', $item->getKey(), null, [
                'warehouse' => $warehouse->name, 'project' => $project->code, 'quantity' => (string) $data['quantity'],
                'value' => $value->toDecimalString(), 'journal_entry' => $entry?->number,
            ], null, $request->user()->getKey());

            return response()->json(['move_id' => $move['id'], 'amount' => $value->toDecimalString(), 'unit_cost' => $move['unit_cost'],
                'journal_entry_id' => $entry?->getKey()], 201);
        });
    }

    /**
     * Module 4: a stock count of one warehouse. Each item counted differently from the books is adjusted to the count:
     * a shortage leaves at the average cost, a surplus comes in at the item's average cost in that warehouse (or its
     * purchase price when none is on hand). The net value posts in one entry against inventory adjustment. A count is of
     * the stock as it stands: dated today, or a day after which nothing moved in that warehouse.
     */
    public function count(Request $request, StockLedger $stock, PostingService $posting, AuditLogger $audit): JsonResponse
    {
        $this->authorizeCompany('inventory.adjust');
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->current->today()],
            'counted' => ['required', 'array', 'min:1', 'max:1000'],
            'counted.*.item_id' => ['required', 'uuid', 'distinct'],
            'counted.*.quantity' => ['required', 'decimal:0,2', 'min:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return DB::transaction(function () use ($data, $stock, $posting, $audit, $request) {
            $company = $this->current->get();
            $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])
                ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);
            if (DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'warehouse_id' => $warehouse->getKey()])->where('moved_on', '>', $data['date'])->exists()) {
                throw ValidationException::withMessages(['date' => ['Stock moved in this warehouse after that day: count it as it stands today.']]);
            }
            $date = new DateTimeImmutable($data['date']);
            $reference = mb_substr('Count ' . $data['date'] . ($data['note'] ? ": {$data['note']}" : ''), 0, 255);
            $base = $company->base_currency;
            $net = \App\Domain\Ledger\Money::zero($base);
            $lines = [];
            foreach ($data['counted'] as $i => $c) {
                $item = Item::query()->find($c['item_id']);
                if ($item === null || ! $item->isStock()) {
                    throw ValidationException::withMessages(["counted.{$i}.item_id" => ['Each line is a stock item of this company.']]);
                }
                $balance = DB::table('stock_balances')->where(['company_id' => $company->getKey(), 'item_id' => $item->getKey(), 'warehouse_id' => $warehouse->getKey()])
                    ->first(['quantity', 'value']);   // kept by the database's own triggers (the app cannot lock it); a count is taken with the warehouse still
                $books = (string) ($balance->quantity ?? '0');
                $diff = bcsub((string) $c['quantity'], $books, 2);
                if (bccomp($diff, '0', 2) === 0) {
                    continue;
                }
                $amount = null;
                if (bccomp($diff, '0', 2) > 0) {
                    $unit = bccomp($books, '0', 2) > 0 ? bcdiv((string) $balance->value, $books, 6) : (string) ($item->purchase_price ?? '0');
                    $amount = bcadd(bcmul($unit, $diff, 6), '0.005', 2);   // half up to the cent (never negative here)
                }
                $move = $stock->move($company->getKey(), $base, $item->getKey(), $warehouse->getKey(), $date, 'adjustment', $diff, '0', $reference, null,
                    $request->user()->getKey(), $amount);
                $net = $net->plus($move['amount']);
                $lines[] = ['item' => $item->name, 'books' => $books, 'counted' => (string) $c['quantity'], 'difference' => $diff, 'value' => $move['amount']->toDecimalString()];
            }

            $entry = null;
            if (! $net->isZero()) {
                $draft = new JournalDraft($company->getKey(), $date, $base, $base, '1', "Stock count of {$warehouse->name} on {$data['date']}", 'stock_count', null);
                $inventory = Account::system('inventory')->getKey();
                $adjustment = Account::system('inventory_adjustment')->getKey();
                $value = $net->isNegative() ? $net->negated() : $net;
                $draft->add($net->isNegative() ? JournalLineDraft::credit($inventory, $value) : JournalLineDraft::debit($inventory, $value));
                $draft->add($net->isNegative() ? JournalLineDraft::debit($adjustment, $value) : JournalLineDraft::credit($adjustment, $value));
                $entry = $posting->post($draft, $request->user()->getKey());
            }
            $audit->record($company->getKey(), 'stock_counted', 'warehouse', $warehouse->getKey(), null, [
                'date' => $data['date'], 'items' => count($data['counted']), 'differences' => $lines, 'net' => $net->toDecimalString(), 'journal_entry' => $entry?->number,
            ], $data['note'] ?? null, $request->user()->getKey());

            return response()->json(['counted' => count($data['counted']), 'differences' => $lines, 'net' => $net->toDecimalString(),
                'journal_entry_id' => $entry?->getKey()], 201);
        });
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
