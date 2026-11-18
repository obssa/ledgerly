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
     * The open order lines with something left to deliver, the earliest wanted first.
     *
     * @return list<array<string, mixed>>
     */
    public function backOrders(): array
    {
        return $this->open()
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->selectRaw('o.id AS order_id, o.number, o.issue_date, o.due_date, c.id AS contact_id, c.name AS customer, l.id AS line_id, l.line_no, '
                . 'l.description, l.item_id, i.sku, i.name AS item_name, i.type AS item_type, l.quantity::text AS ordered, '
                . 'coalesce(x.quantity, 0)::numeric(18,2)::text AS delivered, (l.quantity - coalesce(x.quantity, 0))::numeric(18,2)::text AS remaining')
            ->orderByRaw('o.due_date NULLS LAST')->orderBy('o.issue_date')->orderBy('o.number')->orderBy('l.line_no')
            ->limit(2000)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * For each stock item (all of them, or those given): on hand across the warehouses, reserved by the open orders, and
     * available (which is below zero when the orders want more than there is).
     *
     * @param list<string>|null $itemIds
     * @return array<string, array{on_hand: string, reserved: string, available: string}> by item id
     */
    public function byItem(?array $itemIds = null): array
    {
        $reserved = $this->open()->join('items as i', 'i.id', '=', 'l.item_id')->where('i.type', 'inventory')
            ->when($itemIds !== null, fn (Builder $q) => $q->whereIn('l.item_id', $itemIds))
            ->groupBy('l.item_id')
            ->selectRaw('l.item_id, sum(l.quantity - coalesce(x.quantity, 0))::numeric(18,2)::text AS quantity')
            ->pluck('quantity', 'item_id');
        $ids = $itemIds ?? $reserved->keys()->all();
        $onHand = StockBalance::query()->whereIn('item_id', $ids)->groupBy('item_id')
            ->selectRaw('item_id, sum(quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'item_id');

        $out = [];
        foreach ($ids as $id) {
            $have = (string) ($onHand[$id] ?? '0.00');
            $held = (string) ($reserved[$id] ?? '0.00');
            $out[(string) $id] = ['on_hand' => $have, 'reserved' => $held, 'available' => bcsub($have, $held, 2)];
        }

        return $out;
    }

    /** Lines of confirmed orders with something left, joined to what their live invoices delivered (x.quantity). */
    private function open(): Builder
    {
        $delivered = DB::table('document_lines as dl')->join('documents as dd', 'dd.id', '=', 'dl.document_id')
            ->where('dd.status', '<>', 'void')->whereNotNull('dl.source_line_id')
            ->groupBy('dl.source_line_id')->selectRaw('dl.source_line_id, sum(dl.quantity) AS quantity');

        return DB::table('document_lines as l')->join('documents as o', 'o.id', '=', 'l.document_id')
            ->leftJoinSub($delivered, 'x', 'x.source_line_id', '=', 'l.id')
            ->where('o.type', 'sales_order')->where('o.status', 'accepted')
            ->whereRaw('l.quantity > coalesce(x.quantity, 0)');
    }
}
