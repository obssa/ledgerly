<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\DocumentStock;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Ledger\Exceptions\PeriodLocked;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalLineDraft;
use App\Domain\Ledger\Models\Account;
use App\Domain\Ledger\Money;
use App\Domain\Ledger\PostingService;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 3: goods received notes (069). What of an issued purchase order arrived, and when. Its stock lines come into
 * the warehouse at the order's cost, in the base currency at the order's rate: one entry, Dr Inventory, Cr Goods
 * received not invoiced (GRNI). The order's bills then clear GRNI for what was received (DocumentStock) instead of
 * bringing the goods in again. A note is kept; voiding it takes the goods out again at today's cost, the entry is
 * reversed, and any difference from the value that came in is an inventory adjustment. A note whose goods are billed
 * is not voided until the bill is. Every query is the company's own under row-level security.
 */
final class GoodsReceiving
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly StockLedger $stock,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly DocumentStock $documents,
        private readonly ReceivedGoods $received,
    ) {
    }

    /**
     * Records what arrived: $quantities is order line id => quantity (zero for a line that did not come).
     *
     * @param array<string, string> $quantities
     * @return array<string, mixed> the note
     */
    public function receive(Document $order, array $quantities, string $date, ?string $warehouseId, ?string $reference, ?string $note, string $userId, array $batches = []): array
    {
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($order, $quantities, $date, $warehouseId, $reference, $note, $userId, $company, $base, $batches) {
            $order = Document::query()->lockForUpdate()->with('lines')->findOrFail($order->getKey());
            if ($order->type !== 'purchase_order' || $order->status !== 'accepted') {
                throw new LedgerException('Goods are received against an issued purchase order.');
            }
            if ($date < $order->issue_date->format('Y-m-d') || $date > $this->current->today()) {
                throw ValidationException::withMessages(['received_on' => ["Dated from the order's date ({$order->issue_date->format('Y-m-d')}) to today."]]);
            }
            $warehouse = Warehouse::query()->where('is_active', true)->find($warehouseId ?? $order->warehouse_id);
            if ($warehouse === null) {
                throw ValidationException::withMessages(['warehouse_id' => ['Choose the warehouse the goods arrived in.']]);
            }

            $lines = $order->lines->keyBy(fn ($l) => (string) $l->getKey());
            $stock = Item::query()->whereIn('id', $order->lines->pluck('item_id')->filter())->where('type', 'inventory')->get()->keyBy('id');
            $done = $this->received->byLine((string) $order->getKey());
            $billedIn = DB::table('stock_moves as m')->join('documents as b', 'b.id', '=', 'm.document_id')
                ->where(['m.kind' => 'purchase', 'b.type' => 'bill', 'b.source_document_id' => $order->getKey()])->where('b.status', '<>', 'void')
                ->distinct()->pluck('m.item_id')->map(fn ($id) => (string) $id)->all();

            $take = [];
            foreach ($quantities as $lineId => $quantity) {
                $line = $lines[$lineId] ?? null;
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => ['A line given is not a line of this order.']]);
                }
                if (bccomp((string) $quantity, '0', 2) <= 0) {
                    continue;
                }
                if (! isset($stock[$line->item_id])) {
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no} is not a stock item: only stock is received; it is billed as it is."]]);
                }
                if (in_array((string) $line->item_id, $billedIn, true)) {
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: a bill of this order already brought {$stock[$line->item_id]->name} in."]]);
                }
                $left = bcsub((string) $line->quantity, $done[$lineId]['received'] ?? '0', 2);
                if (bccomp((string) $quantity, $left, 2) > 0) {
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: only {$left} is still to come."]]);
                }
                $take[$lineId] = (string) $quantity;
            }
            if ($take === []) {
                throw ValidationException::withMessages(['lines' => ['Nothing was received: give the quantity that arrived.']]);
            }

            // Each line at the order's cost; the note that completes a line takes what is left of its value, so the
            // received values add up exactly to the order line.
            $values = [];
            $total = Money::zero($base);
            foreach ($take as $lineId => $quantity) {
                $line = $lines[$lineId];
                $full = $this->documents->lineCost($line, $order, $company)->convertTo($base, (string) $order->fx_rate)->roundToCurrency();
                $had = $done[$lineId]['received'] ?? '0';
                $value = bccomp(bcadd($had, $quantity, 2), (string) $line->quantity, 2) === 0
                    ? $full->minus(Money::of($done[$lineId]['received_value'] ?? '0', $base))
                    : $full->multipliedBy($quantity)->dividedBy((string) $line->quantity)->roundToCurrency();
                $value = $value->isNegative() ? Money::zero($base) : $value;
                $values[$lineId] = $value;
                $total = $total->plus($value);
            }

            $id = (string) Str::uuid7();
            $number = 'GRN-' . str_pad((string) $this->posting->nextNumber($company->getKey(), 'goods_receipt'), 5, '0', STR_PAD_LEFT);
            $at = new DateTimeImmutable($date);
            $entryId = null;
            if (! $total->isZero()) {
                $draft = new JournalDraft($company->getKey(), $at, $base, $base, '1', "Goods received {$number} on {$order->number}", 'goods_receipt', $id);
                $draft->add(JournalLineDraft::debit(Account::system('inventory')->getKey(), $total, null, "Goods received, {$number}"));
                $draft->add(JournalLineDraft::credit(Account::system('grni')->getKey(), $total, null, "Received, not yet billed: {$order->number}", (string) $order->contact_id));
                $entryId = $this->posting->post($draft, $userId)->getKey();
            }

            DB::table('goods_receipts')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'purchase_order_id' => $order->getKey(), 'number' => $number, 'received_on' => $date,
                'warehouse_id' => $warehouse->getKey(), 'reference' => $reference, 'note' => $note, 'journal_entry_id' => $entryId, 'created_by' => $userId,
            ]);
            foreach ($take as $lineId => $quantity) {
                $line = $lines[$lineId];
                DB::table('goods_receipt_lines')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'goods_receipt_id' => $id, 'order_line_id' => $lineId,
                    'item_id' => $line->item_id, 'quantity' => $quantity, 'amount' => $values[$lineId]->toDecimalString(),
                ]);
                $own = DocumentStock::baseQuantity((object) ['quantity' => $quantity, 'unit_factor' => $line->unit_factor]);   // 072
                $this->stock->move($company->getKey(), $base, (string) $line->item_id, $warehouse->getKey(), $at, 'purchase', $own, '0',
                    $reference ?? $number, null, $userId, $values[$lineId]->toDecimalString(), goodsReceiptId: $id, batch: $batches[$lineId] ?? null);   // 074
            }

            $this->audit->record($company->getKey(), 'goods_received', 'purchase_order', $order->getKey(), null,
                ['goods_receipt_id' => $id, 'number' => $number, 'received_on' => $date, 'warehouse_id' => $warehouse->getKey(), 'reference' => $reference,
                    'received' => $take, 'value' => $total->toDecimalString(), 'journal_entry_id' => $entryId], userId: $userId);

            return $this->find($id);
        });
    }

    /** Voids a note: its goods go out again, its entry is reversed. Refused while its goods are billed. */
    public function void(string $receiptId, ?string $reason, string $userId): array
    {
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($receiptId, $reason, $userId, $company, $base) {
            $receipt = DB::table('goods_receipts')->where('id', $receiptId)->lockForUpdate()->first();
            if ($receipt === null) {
                abort(404);
            }
            if ($receipt->status !== 'posted') {
                throw new LedgerException("{$receipt->number} is already void.");
            }
            $lines = DB::table('goods_receipt_lines')->where('goods_receipt_id', $receiptId)->get();
            $done = $this->received->byLine((string) $receipt->purchase_order_id);
            foreach ($lines->groupBy('order_line_id') as $lineId => $mine) {
                $without = bcsub($done[$lineId]['received'] ?? '0', $this->sum($mine), 2);
                if (bccomp($done[$lineId]['matched'] ?? '0', $without, 2) > 0) {
                    throw ValidationException::withMessages(['goods_receipt' => ["The goods on {$receipt->number} are billed: void that bill first."]]);
                }
            }

            $closedUntil = $company->books_closed_until?->format('Y-m-d');
            $inClosedPeriod = $closedUntil !== null && $receipt->received_on <= $closedUntil;
            if ($inClosedPeriod && ! $this->current->membership()?->can('periods.reopen')) {
                throw new PeriodLocked("Books are closed until {$closedUntil}. Only an admin can void {$receipt->number}.");
            }
            $date = $inClosedPeriod ? (new DateTimeImmutable($closedUntil))->modify('+1 day') : new DateTimeImmutable($receipt->received_on);

            $reversalId = $receipt->journal_entry_id ? $this->posting->reverse($receipt->journal_entry_id, $date, $userId)->getKey() : null;
            // The reversal takes the received value off the inventory account; the goods go out at today's cost.
            $difference = Money::zero($base);
            // Out again as they came in, in the item's own unit (072): the note's own moves.
            $in = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'goods_receipt_id' => $receiptId, 'kind' => 'purchase'])->orderBy('seq')->get();
            foreach ($in as $l) {
                try {
                    $out = $this->stock->move($company->getKey(), $base, (string) $l->item_id, (string) $l->warehouse_id, $date, 'purchase_return',
                        '-' . $l->quantity, '0', "Void of {$receipt->number}", null, $userId, goodsReceiptId: $receiptId, batchId: $l->batch_id);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'Not enough stock')) {
                        throw ValidationException::withMessages(['goods_receipt' => ["The goods on {$receipt->number} are no longer all in stock: they cannot go out again."]]);
                    }
                    throw $e;
                }
                $difference = $difference->plus(Money::of((string) $l->amount, $base))->minus($out['amount']->negated());
            }
            if (! $difference->isZero()) {
                $draft = new JournalDraft($company->getKey(), $date, $base, $base, '1', "Stock on void of {$receipt->number}: cost against today's average", 'stock_adjustment', $receiptId);
                $inventory = Account::system('inventory')->getKey();
                $adjustment = Account::system('inventory_adjustment')->getKey();
                $draft->add($difference->isNegative() ? JournalLineDraft::credit($inventory, $difference->negated()) : JournalLineDraft::debit($inventory, $difference));
                $draft->add($difference->isNegative() ? JournalLineDraft::debit($adjustment, $difference->negated()) : JournalLineDraft::credit($adjustment, $difference));
                $this->posting->post($draft, $userId);
            }

            DB::table('goods_receipts')->where('id', $receiptId)->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => $userId, 'voided_at' => now()]);
            $this->audit->record($company->getKey(), 'goods_receipt_voided', 'purchase_order', (string) $receipt->purchase_order_id,
                ['goods_receipt_id' => $receiptId, 'number' => $receipt->number, 'status' => 'posted'], ['status' => 'void', 'reversal_entry_id' => $reversalId], $reason, $userId);

            return $this->find($receiptId);
        });
    }

    /** @return array<string, mixed> a note with its lines and the order it received */
    public function find(string $id): array
    {
        $receipt = DB::table('goods_receipts as g')->join('documents as o', 'o.id', '=', 'g.purchase_order_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->where('g.id', $id)
            ->first(['g.id', 'g.number', 'g.received_on', 'g.reference', 'g.note', 'g.status', 'g.journal_entry_id', 'g.void_reason', 'g.voided_at', 'g.created_at',
                'g.purchase_order_id', 'o.number as order_number', 'o.contact_id', 'c.name as supplier', 'g.warehouse_id', 'w.name as warehouse']);
        if ($receipt === null) {
            abort(404);
        }
        $lines = DB::table('goods_receipt_lines as gl')->join('document_lines as l', 'l.id', '=', 'gl.order_line_id')->join('items as i', 'i.id', '=', 'gl.item_id')
            ->where('gl.goods_receipt_id', $id)->orderBy('l.line_no')
            ->get(['gl.id', 'gl.order_line_id', 'l.line_no', 'l.description', 'gl.item_id', 'i.name as item_name', 'i.unit', 'gl.quantity', 'gl.amount']);

        return [...(array) $receipt, 'lines' => $lines->map(fn ($l) => (array) $l)->all()];
    }

    /** @param \Illuminate\Support\Collection<int, object> $lines */
    private function sum($lines): string
    {
        return $lines->reduce(fn (string $s, $l) => bcadd($s, (string) $l->quantity, 2), '0');
    }
}
