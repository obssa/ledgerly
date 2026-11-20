<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

/**
 * What a sales order's live delivery notes delivered, and what its live invoices matched of it (071), read from the
 * notes and matches as they stand. Every query is the company's own under row-level security.
 */
final class DeliveredGoods
{
    /** @return array<string, array{delivered: string, matched: string}> by order line id */
    public function byLine(string $orderId): array
    {
        $delivered = DB::table('delivery_note_lines as dl')->join('delivery_notes as d', 'd.id', '=', 'dl.delivery_note_id')
            ->where('d.sales_order_id', $orderId)->where('d.status', 'posted')->groupBy('dl.order_line_id')
            ->selectRaw('dl.order_line_id AS line_id, sum(dl.quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'line_id');
        $matched = DB::table('invoice_delivery_matches as m')->join('documents as i', 'i.id', '=', 'm.invoice_id')
            ->where('i.source_document_id', $orderId)->where('i.status', '<>', 'void')->groupBy('m.order_line_id')
            ->selectRaw('m.order_line_id AS line_id, sum(m.quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'line_id');
        $out = [];
        foreach ($delivered->keys()->merge($matched->keys())->unique() as $id) {
            $out[(string) $id] = ['delivered' => (string) ($delivered[$id] ?? '0.00'), 'matched' => (string) ($matched[$id] ?? '0.00')];
        }

        return $out;
    }

    /**
     * @return list<string> the items the order's live notes delivered: its invoices bill them without moving them (rule 3
     * of 071); 097: with the parts of the bundles they delivered, which its invoices do not take out either
     */
    public function items(string $orderId): array
    {
        $items = DB::table('delivery_note_lines as dl')->join('delivery_notes as d', 'd.id', '=', 'dl.delivery_note_id')
            ->where('d.sales_order_id', $orderId)->where('d.status', 'posted')->distinct()->pluck('dl.item_id')->map(fn ($id) => (string) $id)->all();
        $parts = $items === [] ? [] : DB::table('item_components')->whereIn('bundle_id', $items)->pluck('component_id')->map(fn ($id) => (string) $id)->all();

        return array_values(array_unique([...$items, ...$parts]));
    }

    /**
     * 097: the stock parts of the bundles given, each with its quantity per bundle (in the part's own unit). A bundle's
     * parts are fixed once it is on a document (073).
     *
     * @param list<string> $bundleIds
     * @return array<string, list<array{item_id: string, quantity: string}>> by bundle id; a bundle without a stock part is left out
     */
    public function stockParts(array $bundleIds): array
    {
        if ($bundleIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('item_components as c')->join('items as p', 'p.id', '=', 'c.component_id')->where('p.type', 'inventory')
            ->whereIn('c.bundle_id', $bundleIds)->orderBy('p.name')->get(['c.bundle_id', 'c.component_id', 'c.quantity']) as $r) {
            $out[(string) $r->bundle_id][] = ['item_id' => (string) $r->component_id, 'quantity' => (string) $r->quantity];
        }

        return $out;
    }

    /**
     * Goods delivered not invoiced, line by line: what the live notes delivered less what the live invoices matched.
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(): array
    {
        $delivered = DB::table('delivery_note_lines as dl')->join('delivery_notes as d', 'd.id', '=', 'dl.delivery_note_id')
            ->where('d.status', 'posted')->groupBy('dl.order_line_id')
            ->selectRaw('dl.order_line_id, sum(dl.quantity) AS quantity, max(d.delivered_on) AS last_delivered');
        $matched = DB::table('invoice_delivery_matches as m')->join('documents as i', 'i.id', '=', 'm.invoice_id')
            ->where('i.status', '<>', 'void')->groupBy('m.order_line_id')->selectRaw('m.order_line_id, sum(m.quantity) AS quantity');

        return DB::query()->fromSub($delivered, 'r')
            ->join('document_lines as l', 'l.id', '=', 'r.order_line_id')
            ->join('documents as o', 'o.id', '=', 'l.document_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoinSub($matched, 'm', 'm.order_line_id', '=', 'r.order_line_id')
            ->whereRaw('r.quantity > coalesce(m.quantity, 0)')
            ->orderBy('r.last_delivered')->orderBy('o.number')->orderBy('l.line_no')
            ->limit(2000)
            ->selectRaw('o.id AS order_id, o.number, c.id AS contact_id, c.name AS customer, l.id AS line_id, l.line_no, l.description, l.item_id, '
                . 'i.name AS item_name, i.unit, r.last_delivered, l.unit_price::text AS unit_price, r.quantity::numeric(18,2)::text AS delivered, '
                . 'coalesce(m.quantity, 0)::numeric(18,2)::text AS invoiced, (r.quantity - coalesce(m.quantity, 0))::numeric(18,2)::text AS open')
            ->get()->map(fn ($r) => (array) $r)->all();
    }
}
