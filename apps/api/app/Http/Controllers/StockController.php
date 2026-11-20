<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Actions\SaveQuote;
use App\Domain\Inventory\Reservations;
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
            'batch' => ['nullable', 'string', 'max:2000'],   // 074: move this batch; none moves the first expiring
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
                '-' . $data['quantity'], '0', $reference, null, $request->user()->getKey(), null, $group, batch: ['number' => $data['batch'] ?? null]);
            // In as it went out: batch by batch (074), each at the value that left.
            $value = \App\Domain\Ledger\Money::zero($company->base_currency);
            foreach ($out['parts'] as $part) {
                $in = $stock->move($company->getKey(), $company->base_currency, $item->getKey(), $to->getKey(), $date, 'transfer_in',
                    ltrim($part['quantity'], '-'), '0', $data['reference'] ?? "Transfer from {$from->name}", null, $request->user()->getKey(),
                    ltrim($part['amount'], '-'), $group, batchId: $part['batch_id']);
                $value = $value->plus($in['amount']);
            }
            $in = ['amount' => $value, 'unit_cost' => $out['unit_cost']];

            $audit->record($company->getKey(), 'stock_transferred', 'item', $item->getKey(), null, [
                'from' => $from->name, 'to' => $to->name, 'quantity' => (string) $data['quantity'], 'value' => $in['amount']->toDecimalString(),
            ], userId: $request->user()->getKey());

            return response()->json(['transfer_group' => $group, 'value' => $in['amount']->toDecimalString(), 'unit_cost' => $in['unit_cost']], 201);
        });
    }

    /**
     * 074: what is on hand by batch, the first to expire first; with within_days, only batches expiring by then
     * (already expired ones included).
     */
    public function batches(Request $request): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $q = $request->validate(['within_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'warehouse_id' => ['nullable', 'uuid']]);
        $today = $this->current->today();
        $until = isset($q['within_days']) ? (new DateTimeImmutable($today))->modify("+{$q['within_days']} days")->format('Y-m-d') : null;

        $rows = DB::table('batch_balances as bb')->join('stock_batches as b', 'b.id', '=', 'bb.batch_id')
            ->join('items as i', 'i.id', '=', 'bb.item_id')->join('warehouses as w', 'w.id', '=', 'bb.warehouse_id')
            ->where('bb.quantity', '>', 0)
            ->when($q['warehouse_id'] ?? null, fn ($b, $id) => $b->where('bb.warehouse_id', $id))
            ->when($until, fn ($b, $d) => $b->whereNotNull('b.expires_on')->where('b.expires_on', '<=', $d))
            ->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('i.name')->orderBy('b.number')->limit(2000)
            ->get(['bb.item_id', 'i.name as item_name', 'i.sku', 'i.unit', 'b.id as batch_id', 'b.number', 'b.expires_on', 'bb.warehouse_id', 'w.name as warehouse', 'bb.quantity']);

        return response()->json(['data' => $rows->map(fn ($r) => [...(array) $r, 'expired' => $r->expires_on !== null && (string) $r->expires_on < $today])->values(), 'today' => $today]);
    }

    /** Stock items at or below their reorder point, across all warehouses. */
    /**
     * Stock items running low (075): what is on hand, plus what is on order from suppliers, less what the open sales
     * orders hold, at or below the reorder point. The suggested order brings it back up to the maximum level (or to the
     * reorder point when there is none), from the preferred supplier at the item's purchase price.
     */
    public function reorder(Reservations $reservations): JsonResponse
    {
        $this->authorizeCompany('inventory.view');
        $items = Item::query()->where(['is_active' => true, 'type' => 'inventory'])->whereNotNull('reorder_point')->orderBy('name')->get();
        $stock = $reservations->byItem($items->pluck('id')->map(fn ($id) => (string) $id)->values()->all());
        $suppliers = DB::table('contacts')->whereIn('id', $items->pluck('preferred_supplier_id')->filter())->pluck('name', 'id');

        $rows = [];
        foreach ($items as $i) {
            $s = $stock[(string) $i->getKey()] ?? ['on_hand' => '0.00', 'reserved' => '0.00', 'on_order' => '0.00'];
            $position = bcsub(bcadd((string) $s['on_hand'], (string) $s['on_order'], 2), (string) $s['reserved'], 2);
            if (bccomp($position, (string) $i->reorder_point, 2) > 0) {
                continue;
            }
            $target = (string) ($i->max_level ?? $i->reorder_point);
            $suggested = bcsub($target, $position, 2);
            $rows[] = [
                'item' => $i->only(['id', 'sku', 'name', 'unit', 'reorder_point', 'max_level', 'purchase_price', 'preferred_supplier_id']),
                'supplier' => $i->preferred_supplier_id ? ['id' => $i->preferred_supplier_id, 'name' => $suppliers[$i->preferred_supplier_id] ?? null] : null,
                'on_hand' => (string) $s['on_hand'], 'on_order' => (string) $s['on_order'], 'reserved' => (string) $s['reserved'],
                'position' => $position, 'suggested' => bccomp($suggested, '0', 2) > 0 ? $suggested : '0.00',
            ];
        }

        return response()->json(['data' => $rows, 'can' => ['order' => (bool) $this->current->membership()?->can('bills.create')]]);
    }

    /**
     * 075: draft purchase orders for the items given (item and quantity), one per preferred supplier, at each item's
     * purchase price, the stock arriving in the warehouse given. Nothing posts; they are issued as any purchase order.
     */
    public function orderLow(Request $request, SaveQuote $save): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
        ]);
        $warehouse = Warehouse::query()->where('is_active', true)->find($data['warehouse_id'])
            ?? throw ValidationException::withMessages(['warehouse_id' => ['Choose an active warehouse of this company.']]);
        // Loaded through the company-scoped model (CLAUDE.md rule 4).
        $items = Item::query()->where(['is_active' => true, 'type' => 'inventory'])->whereIn('id', array_column($data['lines'], 'item_id'))->get()->keyBy('id');
        $bySupplier = [];
        foreach ($data['lines'] as $n => $l) {
            $item = $items[$l['item_id']] ?? throw ValidationException::withMessages(["lines.{$n}.item_id" => ['Each line is an active stock item of this company.']]);
            if ($item->preferred_supplier_id === null) {
                throw ValidationException::withMessages(["lines.{$n}.item_id" => ["{$item->name} has no preferred supplier: set one on the item first."]]);
            }
            $bySupplier[$item->preferred_supplier_id][] = [$item, (string) $l['quantity']];
        }

        $company = $this->current->get();
        $inventory = Account::system('inventory')->getKey();
        $today = $this->current->today();
        $made = DB::transaction(function () use ($bySupplier, $save, $request, $company, $inventory, $today, $warehouse) {
            $made = [];
            foreach ($bySupplier as $supplier => $lines) {
                $order = $save->handle([
                    'contact_id' => $supplier, 'issue_date' => $today, 'currency' => $company->base_currency, 'warehouse_id' => $warehouse->getKey(),
                    'notes' => 'Suggested by the reorder levels.',
                    'lines' => array_map(fn (array $l) => ['description' => $l[0]->name, 'quantity' => $l[1], 'unit_price' => (string) ($l[0]->purchase_price ?? '0'),
                        'account_id' => $inventory, 'item_id' => $l[0]->getKey()], $lines),
                ], 'purchase_order', null, $request->user()->getKey());
                $made[] = ['id' => $order->getKey(), 'contact_id' => $supplier, 'lines' => count($lines), 'total' => (string) $order->total];
            }

            return $made;
        });

        return response()->json(['data' => $made], 201);
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
            'counted.*.batch' => ['nullable', 'string', 'max:2000'],   // 074: the batch found over, for an item tracked by batch
            'counted.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
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
                    $request->user()->getKey(), $amount, batch: ['number' => $c['batch'] ?? null, 'expires_on' => $c['expires_on'] ?? null]);
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
            'batch' => ['nullable', 'string', 'max:2000'],   // 074: the batch coming in (or going out) for an item tracked by batch
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
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
                $opening ? 'opening' : 'adjustment', (string) $data['quantity'], (string) ($data['unit_cost'] ?? '0'), $data['reason'], null, $request->user()->getKey(),
                batch: ['number' => $data['batch'] ?? null, 'expires_on' => $data['expires_on'] ?? null]);

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
