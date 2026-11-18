<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use Illuminate\Support\Facades\DB;

/**
 * What a purchase order's live goods received notes received, and what its live bills matched of it (069), read from
 * the notes and matches as they stand. Every query is the company's own under row-level security.
 */
final class ReceivedGoods
{
    /**
     * @return array<string, array{received: string, received_value: string, matched: string, matched_value: string, warehouse_id: ?string}> by order line id
     */
    public function byLine(string $orderId): array
    {
        $received = DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
            ->where('g.purchase_order_id', $orderId)->where('g.status', 'posted')->groupBy('gl.order_line_id')
            ->selectRaw('gl.order_line_id AS line_id, sum(gl.quantity)::numeric(18,2)::text AS quantity, sum(gl.amount)::numeric(20,2)::text AS amount, '
                . '(array_agg(g.warehouse_id ORDER BY g.received_on DESC, g.created_at DESC))[1] AS warehouse_id')
            ->get()->keyBy('line_id');
        $matched = DB::table('bill_receipt_matches as m')->join('documents as b', 'b.id', '=', 'm.bill_id')
            ->where('b.source_document_id', $orderId)->where('b.status', '<>', 'void')->groupBy('m.order_line_id')
            ->selectRaw('m.order_line_id AS line_id, sum(m.quantity)::numeric(18,2)::text AS quantity, sum(m.amount)::numeric(20,2)::text AS amount')
            ->get()->keyBy('line_id');
        $out = [];
        foreach ($received->keys()->merge($matched->keys())->unique() as $id) {
            $out[(string) $id] = [
                'received' => (string) ($received[$id]->quantity ?? '0.00'), 'received_value' => (string) ($received[$id]->amount ?? '0.00'),
                'matched' => (string) ($matched[$id]->quantity ?? '0.00'), 'matched_value' => (string) ($matched[$id]->amount ?? '0.00'),
                'warehouse_id' => isset($received[$id]) ? (string) $received[$id]->warehouse_id : null,
            ];
        }

        return $out;
    }

    /** @return list<string> the items the order's live notes received: its bills clear GRNI for them (rule 3 of 069) */
    public function items(string $orderId): array
    {
        return DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
            ->where('g.purchase_order_id', $orderId)->where('g.status', 'posted')->distinct()->pluck('gl.item_id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Goods received not invoiced, line by line: what the live notes received less what the live bills matched, with its
     * value at the order's cost. The values add up to the balance of the GRNI account.
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(): array
    {
        $received = DB::table('goods_receipt_lines as gl')->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
            ->where('g.status', 'posted')->groupBy('gl.order_line_id')
            ->selectRaw('gl.order_line_id, sum(gl.quantity) AS quantity, sum(gl.amount) AS amount, max(g.received_on) AS last_received');
        $matched = DB::table('bill_receipt_matches as m')->join('documents as b', 'b.id', '=', 'm.bill_id')
            ->where('b.status', '<>', 'void')->groupBy('m.order_line_id')
            ->selectRaw('m.order_line_id, sum(m.quantity) AS quantity, sum(m.amount) AS amount');

        return DB::query()->fromSub($received, 'r')
            ->join('document_lines as l', 'l.id', '=', 'r.order_line_id')
            ->join('documents as o', 'o.id', '=', 'l.document_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoinSub($matched, 'm', 'm.order_line_id', '=', 'r.order_line_id')
            ->whereRaw('(r.quantity > coalesce(m.quantity, 0) OR r.amount <> coalesce(m.amount, 0))')
            ->orderBy('r.last_received')->orderBy('o.number')->orderBy('l.line_no')
            ->limit(2000)
            ->selectRaw('o.id AS order_id, o.number, c.id AS contact_id, c.name AS supplier, l.id AS line_id, l.line_no, l.description, l.item_id, '
                . 'i.name AS item_name, i.unit, r.last_received, r.quantity::numeric(18,2)::text AS received, coalesce(m.quantity, 0)::numeric(18,2)::text AS billed, '
                . '(r.quantity - coalesce(m.quantity, 0))::numeric(18,2)::text AS open, (r.amount - coalesce(m.amount, 0))::numeric(20,2)::text AS value')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Whether any of the bill's lines were matched to goods received (a posted bill so matched is voided, not edited). */
    public function billMatched(string $billId): bool
    {
        return DB::table('bill_receipt_matches')->where('bill_id', $billId)->exists();
    }
}
