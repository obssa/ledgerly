<?php

declare(strict_types=1);

namespace App\Domain\Sales;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: delivery notes (071). What of a confirmed sales order left for the customer, and when. Its stock lines
 * leave the warehouse that day at their cost (the database decides it), and the cost of goods sold is recorded then:
 * one entry, Dr Cost of goods sold, Cr Inventory. The order's invoices then bill those goods without moving them again
 * (DocumentStock). A note is kept; voiding it brings its goods back at the cost they left with and reverses its entry.
 * A note whose goods are invoiced is not voided until the invoice is. Every query is the company's own under row-level
 * security.
 */
final class Delivering
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly StockLedger $stock,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly DeliveredGoods $delivered,
    ) {
    }

    /**
     * Records what left: $quantities is order line id => quantity (zero for a line that did not go).
     *
     * @param array<string, string> $quantities
     * @return array<string, mixed> the note
     */
    public function deliver(Document $order, array $quantities, string $date, ?string $warehouseId, ?string $reference, ?string $note, string $userId): array
    {
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($order, $quantities, $date, $warehouseId, $reference, $note, $userId, $company, $base) {
            $order = Document::query()->lockForUpdate()->with('lines')->findOrFail($order->getKey());
            if ($order->type !== 'sales_order' || $order->status !== 'accepted') {
                throw new LedgerException('Goods are delivered against a confirmed sales order.');
            }
            if ($date < $order->issue_date->format('Y-m-d') || $date > $this->current->today()) {
                throw ValidationException::withMessages(['delivered_on' => ["Dated from the order's date ({$order->issue_date->format('Y-m-d')}) to today."]]);
            }
            $warehouse = Warehouse::query()->where('is_active', true)->find($warehouseId ?? $order->warehouse_id);
            if ($warehouse === null) {
                throw ValidationException::withMessages(['warehouse_id' => ['Choose the warehouse the goods leave from.']]);
            }

            $lines = $order->lines->keyBy(fn ($l) => (string) $l->getKey());
            $stock = Item::query()->whereIn('id', $order->lines->pluck('item_id')->filter())->where('type', 'inventory')->get()->keyBy('id');
            $done = $this->delivered->byLine((string) $order->getKey());
            $invoicedOut = DB::table('stock_moves as m')->join('documents as i', 'i.id', '=', 'm.document_id')
                ->where(['m.kind' => 'sale', 'i.type' => 'invoice', 'i.source_document_id' => $order->getKey()])->where('i.status', '<>', 'void')
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
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no} is not a stock item: only stock is delivered on a note; it is invoiced as it is."]]);
                }
                if (in_array((string) $line->item_id, $invoicedOut, true)) {
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: an invoice of this order already took {$stock[$line->item_id]->name} out."]]);
                }
                $left = bcsub((string) $line->quantity, $done[$lineId]['delivered'] ?? '0', 2);
                if (bccomp((string) $quantity, $left, 2) > 0) {
                    throw ValidationException::withMessages(['lines' => ["Line {$line->line_no}: only {$left} is still to deliver."]]);
                }
                $take[$lineId] = (string) $quantity;
            }
            if ($take === []) {
                throw ValidationException::withMessages(['lines' => ['Nothing was delivered: give the quantity that left.']]);
            }

            $id = (string) Str::uuid7();
            $number = 'DN-' . str_pad((string) $this->posting->nextNumber($company->getKey(), 'delivery_note'), 5, '0', STR_PAD_LEFT);
            $at = new DateTimeImmutable($date);
            DB::table('delivery_notes')->insert([
                'id' => $id, 'company_id' => $company->getKey(), 'sales_order_id' => $order->getKey(), 'number' => $number, 'delivered_on' => $date,
                'warehouse_id' => $warehouse->getKey(), 'reference' => $reference, 'note' => $note, 'created_by' => $userId,
            ]);
            $cost = Money::zero($base);
            foreach ($take as $lineId => $quantity) {
                $line = $lines[$lineId];
                DB::table('delivery_note_lines')->insert([
                    'id' => (string) Str::uuid7(), 'company_id' => $company->getKey(), 'delivery_note_id' => $id, 'order_line_id' => $lineId,
                    'item_id' => $line->item_id, 'quantity' => $quantity,
                ]);
                $own = DocumentStock::baseQuantity((object) ['quantity' => $quantity, 'unit_factor' => $line->unit_factor]);   // 072
                $move = $this->stock->move($company->getKey(), $base, (string) $line->item_id, $warehouse->getKey(), $at, 'sale', '-' . $own, '0',
                    $number, null, $userId, deliveryNoteId: $id);
                $cost = $cost->plus($move['amount']->negated());
            }

            $entryId = null;
            if (! $cost->isZero()) {
                $draft = new JournalDraft($company->getKey(), $at, $base, $base, '1', "Goods delivered {$number} on {$order->number}", 'delivery_note', $id);
                $draft->add(JournalLineDraft::debit(Account::system('cogs')->getKey(), $cost, null, "Cost of goods delivered, {$number}"));
                $draft->add(JournalLineDraft::credit(Account::system('inventory')->getKey(), $cost, null, "Goods delivered, {$number}"));
                $entryId = $this->posting->post($draft, $userId)->getKey();
                DB::table('delivery_notes')->where('id', $id)->update(['journal_entry_id' => $entryId]);
            }

            $this->audit->record($company->getKey(), 'goods_delivered', 'sales_order', $order->getKey(), null,
                ['delivery_note_id' => $id, 'number' => $number, 'delivered_on' => $date, 'warehouse_id' => $warehouse->getKey(), 'reference' => $reference,
                    'delivered' => $take, 'cost' => $cost->toDecimalString(), 'journal_entry_id' => $entryId], userId: $userId);

            return $this->find($id);
        });
    }

    /** Voids a note: its goods come back at the cost they left with, its entry is reversed. Refused while they are invoiced. */
    public function void(string $noteId, ?string $reason, string $userId): array
    {
        $company = $this->current->get();
        $base = $company->base_currency;

        return DB::transaction(function () use ($noteId, $reason, $userId, $company, $base) {
            $note = DB::table('delivery_notes')->where('id', $noteId)->lockForUpdate()->first();
            if ($note === null) {
                abort(404);
            }
            if ($note->status !== 'posted') {
                throw new LedgerException("{$note->number} is already void.");
            }
            $lines = DB::table('delivery_note_lines')->where('delivery_note_id', $noteId)->get();
            $done = $this->delivered->byLine((string) $note->sales_order_id);
            foreach ($lines->groupBy('order_line_id') as $lineId => $mine) {
                $without = bcsub($done[$lineId]['delivered'] ?? '0', $mine->reduce(fn (string $s, $l) => bcadd($s, (string) $l->quantity, 2), '0'), 2);
                if (bccomp($done[$lineId]['matched'] ?? '0', $without, 2) > 0) {
                    throw ValidationException::withMessages(['delivery_note' => ["The goods on {$note->number} are invoiced: void that invoice first."]]);
                }
            }

            $closedUntil = $company->books_closed_until?->format('Y-m-d');
            $inClosedPeriod = $closedUntil !== null && $note->delivered_on <= $closedUntil;
            if ($inClosedPeriod && ! $this->current->membership()?->can('periods.reopen')) {
                throw new PeriodLocked("Books are closed until {$closedUntil}. Only an admin can void {$note->number}.");
            }
            $date = $inClosedPeriod ? (new DateTimeImmutable($closedUntil))->modify('+1 day') : new DateTimeImmutable($note->delivered_on);

            $reversalId = $note->journal_entry_id ? $this->posting->reverse($note->journal_entry_id, $date, $userId)->getKey() : null;
            // Back in at the cost they left with: the reversal puts back exactly that.
            $moves = DB::table('stock_moves')->where(['company_id' => $company->getKey(), 'delivery_note_id' => $noteId, 'kind' => 'sale'])->orderBy('seq')->get();
            foreach ($moves as $m) {
                $this->stock->move($company->getKey(), $base, (string) $m->item_id, (string) $m->warehouse_id, $date, 'sale_return',
                    ltrim((string) $m->quantity, '-'), '0', "Void of {$note->number}", null, $userId, ltrim((string) $m->amount, '-'), deliveryNoteId: $noteId, batchId: $m->batch_id);
            }

            DB::table('delivery_notes')->where('id', $noteId)->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => $userId, 'voided_at' => now()]);
            $this->audit->record($company->getKey(), 'delivery_note_voided', 'sales_order', (string) $note->sales_order_id,
                ['delivery_note_id' => $noteId, 'number' => $note->number, 'status' => 'posted'], ['status' => 'void', 'reversal_entry_id' => $reversalId], $reason, $userId);

            return $this->find($noteId);
        });
    }

    /** @return array<string, mixed> a note with its lines and the order it delivered */
    public function find(string $id): array
    {
        $note = DB::table('delivery_notes as d')->join('documents as o', 'o.id', '=', 'd.sales_order_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->where('d.id', $id)
            ->first(['d.id', 'd.number', 'd.delivered_on', 'd.reference', 'd.note', 'd.status', 'd.journal_entry_id', 'd.void_reason', 'd.voided_at', 'd.created_at',
                'd.sales_order_id', 'o.number as order_number', 'o.contact_id', 'c.name as customer', 'd.warehouse_id', 'w.name as warehouse']);
        if ($note === null) {
            abort(404);
        }
        $cost = DB::table('stock_moves')->where(['delivery_note_id' => $id, 'kind' => 'sale'])->groupBy('item_id')->selectRaw('item_id, (-sum(amount))::numeric(20,2)::text AS cost')
            ->pluck('cost', 'item_id');
        $lines = DB::table('delivery_note_lines as dl')->join('document_lines as l', 'l.id', '=', 'dl.order_line_id')->join('items as i', 'i.id', '=', 'dl.item_id')
            ->where('dl.delivery_note_id', $id)->orderBy('l.line_no')
            ->get(['dl.id', 'dl.order_line_id', 'l.line_no', 'l.description', 'dl.item_id', 'i.name as item_name', 'i.unit', 'dl.quantity']);

        return [...(array) $note, 'cost' => $cost->reduce(fn (string $s, $v) => bcadd($s, (string) $v, 2), '0.00'),
            'lines' => $lines->map(fn ($l) => (array) $l)->all()];
    }
}
