<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\StockBalance;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Module 2 and 4: what confirmed sales orders still owe their customers (067). An order line not yet delivered in
 * full is a back-order; what is left of a stock item on the open orders is reserved for them, and what is on hand less
 * that is available. Nothing is held in the stock itself: the figures are read from the orders as they stand.
 * Every query is the company's own under row-level security, and no text from a request becomes SQL.
 */
final class Reservations
{
    /**
     * The open order lines with something left to deliver (a sales order) or to bill (a purchase order, 068), the
     * earliest wanted first.
     *
     * @return list<array<string, mixed>>
     */
    public function backOrders(string $type = 'sales_order'): array
    {
        return $this->open($type)
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->selectRaw('o.id AS order_id, o.number, o.issue_date, o.due_date, c.id AS contact_id, c.name AS customer, l.id AS line_id, l.line_no, '
                . 'l.description, l.item_id, i.sku, i.name AS item_name, i.type AS item_type, coalesce(l.unit_name, i.unit) AS unit, l.quantity::text AS ordered, '
                . self::done($type) . '::numeric(18,2)::text AS delivered, (l.quantity - ' . self::done($type) . ')::numeric(18,2)::text AS remaining')
            ->orderByRaw('o.due_date NULLS LAST')->orderBy('o.issue_date')->orderBy('o.number')->orderBy('l.line_no')
            ->limit(2000)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * For each stock item (all of them, or those given): on hand across the warehouses, reserved by the open sales
     * orders, available (below zero when the orders want more than there is), and on order from suppliers (068).
     *
     * @param list<string>|null $itemIds
     * @return array<string, array{on_hand: string, reserved: string, available: string, on_order: string}> by item id
     */
    public function byItem(?array $itemIds = null): array
    {
        $left = fn (string $type) => $this->open($type)->join('items as i', 'i.id', '=', 'l.item_id')->where('i.type', 'inventory')
            ->when($itemIds !== null, fn (Builder $q) => $q->whereIn('l.item_id', $itemIds))
            ->groupBy('l.item_id')
            ->selectRaw('l.item_id, sum(round((l.quantity - ' . self::done($type) . ') * l.unit_factor, 2))::numeric(18,2)::text AS quantity')   // 072: in the item's own unit
            ->pluck('quantity', 'item_id');
        $reserved = $left('sales_order');
        // 073: a bundle on an open sales order holds its stock parts (what is still to deliver, times each part's quantity).
        $parts = $this->open('sales_order')->join('items as b', 'b.id', '=', 'l.item_id')->where('b.type', 'bundle')
            ->join('item_components as c', 'c.bundle_id', '=', 'l.item_id')->join('items as p', 'p.id', '=', 'c.component_id')->where('p.type', 'inventory')
            ->when($itemIds !== null, fn (Builder $q) => $q->whereIn('c.component_id', $itemIds))
            ->groupBy('c.component_id')
            ->selectRaw('c.component_id AS item_id, sum(round((l.quantity - ' . self::done('sales_order') . ') * l.unit_factor * c.quantity, 2))::numeric(18,2)::text AS quantity')
            ->pluck('quantity', 'item_id');
        foreach ($parts as $id => $q) {
            $reserved[$id] = bcadd((string) ($reserved[$id] ?? '0'), (string) $q, 2);
        }
        $ordered = $left('purchase_order');
        $ids = $itemIds ?? $reserved->keys()->merge($ordered->keys())->unique()->values()->all();
        $onHand = StockBalance::query()->whereIn('item_id', $ids)->groupBy('item_id')
            ->selectRaw('item_id, sum(quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'item_id');

        $out = [];
        foreach ($ids as $id) {
            $have = (string) ($onHand[$id] ?? '0.00');
            $held = (string) ($reserved[$id] ?? '0.00');
            $out[(string) $id] = ['on_hand' => $have, 'reserved' => $held, 'available' => bcsub($have, $held, 2),
                'on_order' => (string) ($ordered[$id] ?? '0.00')];
        }

        return $out;
    }

    /** Lines of confirmed (or issued) orders with something left, joined to what their live invoices or bills took (x.quantity). */
    private function open(string $type): Builder
    {
        $delivered = DB::table('document_lines as dl')->join('documents as dd', 'dd.id', '=', 'dl.document_id')
            ->where('dd.status', '<>', 'void')->whereNotNull('dl.source_line_id')
            ->groupBy('dl.source_line_id')->selectRaw('dl.source_line_id, sum(dl.quantity) AS quantity');

        // 069: goods that arrived on a goods received note are no longer on order, billed or not.
        $received = DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
            ->where('g.status', 'posted')->groupBy('gl.order_line_id')->selectRaw('gl.order_line_id, sum(gl.quantity) AS quantity');

        // 071: goods that left on a delivery note are no longer reserved, invoiced or not.
        $shipped = DB::table('delivery_note_lines as dl')->join('delivery_notes as d', 'd.id', '=', 'dl.delivery_note_id')
            ->where('d.status', 'posted')->groupBy('dl.order_line_id')->selectRaw('dl.order_line_id, sum(dl.quantity) AS quantity');

        return DB::table('document_lines as l')->join('documents as o', 'o.id', '=', 'l.document_id')
            ->leftJoinSub($delivered, 'x', 'x.source_line_id', '=', 'l.id')
            ->when($type === 'purchase_order', fn (Builder $q) => $q->leftJoinSub($received, 'r', 'r.order_line_id', '=', 'l.id'))
            ->when($type === 'sales_order', fn (Builder $q) => $q->leftJoinSub($shipped, 'r', 'r.order_line_id', '=', 'l.id'))
            ->where('o.type', $type)->where('o.status', 'accepted')
            ->whereRaw('l.quantity > ' . self::done($type));
    }

    /**
     * What of an order line is done, as fixed SQL: invoiced or delivered on a note for a sales order, billed or received
     * for a purchase order (r is the notes' quantity).
     */
    private static function done(string $type): string
    {
        return in_array($type, ['purchase_order', 'sales_order'], true) ? 'greatest(coalesce(x.quantity, 0), coalesce(r.quantity, 0))' : 'coalesce(x.quantity, 0)';
    }
}
