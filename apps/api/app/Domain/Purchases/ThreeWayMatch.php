<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Sales\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Module 3: the three-way match (069). A bill made from a purchase order, line by line, against what was ordered and
 * what arrived on goods received notes. It warns; it never blocks (the database refuses billing more than was
 * received or ordered on its own). Prices are compared net of discount, in the documents' own currency.
 */
final class ThreeWayMatch
{
    public function __construct(private readonly ReceivedGoods $received)
    {
    }

    /**
     * @return array{order_id: string, order_number: ?string, lines: list<array<string, mixed>>, warnings: list<string>}|null
     */
    public function forBill(Document $bill): ?array
    {
        if ($bill->type !== 'bill' || $bill->source_document_id === null) {
            return null;
        }
        $order = Document::query()->with('lines')->find($bill->source_document_id);
        if ($order === null || $order->type !== 'purchase_order') {
            return null;
        }
        $ordered = $order->lines->keyBy(fn ($l) => (string) $l->getKey());
        $received = $this->received->byLine((string) $order->getKey());
        $receivedItems = $this->received->items((string) $order->getKey());
        // Billed on the order's other live bills, line by line.
        $elsewhere = DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.source_document_id', $order->getKey())->where('d.id', '<>', $bill->getKey())->where('d.status', '<>', 'void')
            ->whereNotNull('l.source_line_id')->groupBy('l.source_line_id')
            ->selectRaw('l.source_line_id, sum(l.quantity)::numeric(18,2)::text AS quantity')->pluck('quantity', 'source_line_id');

        $lines = [];
        $warnings = [];
        $billLines = $bill->relationLoaded('lines') ? $bill->lines : $bill->lines()->get();
        foreach ($billLines->sortBy('line_no') as $line) {
            $o = $line->source_line_id !== null ? ($ordered[(string) $line->source_line_id] ?? null) : null;
            if ($o === null) {
                continue;
            }
            $id = (string) $o->getKey();
            $net = fn ($l) => bcdiv((string) $l->line_subtotal, bccomp((string) $l->quantity, '0', 2) === 0 ? '1' : (string) $l->quantity, 4);
            $orderPrice = $net($o);
            $billPrice = $net($line);
            $byReceipt = in_array((string) $o->item_id, $receivedItems, true);
            $before = (string) ($elsewhere[$id] ?? '0.00');
            $limit = $byReceipt ? bcsub($received[$id]['received'] ?? '0', $before, 2) : bcsub((string) $o->quantity, $before, 2);
            $priceDiffers = bccomp(bcsub($billPrice, $orderPrice, 4), '0', 2) !== 0;
            $over = bccomp((string) $line->quantity, $limit, 2) > 0;
            $change = bccomp($orderPrice, '0', 4) === 0 ? null : bcmul(bcdiv(bcsub($billPrice, $orderPrice, 4), $orderPrice, 6), '100', 1);
            if ($priceDiffers) {
                $warnings[] = "Line {$line->line_no}: billed at " . bcadd($billPrice, '0', 2) . ', ordered at ' . bcadd($orderPrice, '0', 2)
                    . ($change !== null ? " ({$change}%)" : '') . '.';
            }
            if ($over) {
                $warnings[] = "Line {$line->line_no}: bills {$line->quantity}, but only {$limit} " . ($byReceipt ? 'was received and is not yet billed.' : 'is left on the order.');
            }
            $lines[] = [
                'line_id' => (string) $line->getKey(), 'line_no' => $line->line_no, 'description' => $line->description,
                'ordered' => (string) $o->quantity, 'received' => $byReceipt ? ($received[$id]['received'] ?? '0.00') : null,
                'billed_elsewhere' => $before, 'quantity' => (string) $line->quantity,
                'order_price' => bcadd($orderPrice, '0', 2), 'bill_price' => bcadd($billPrice, '0', 2), 'price_change' => $change,
                'by_receipt' => $byReceipt, 'price_differs' => $priceDiffers, 'over' => $over,
            ];
        }

        return ['order_id' => (string) $order->getKey(), 'order_number' => $order->number, 'lines' => $lines, 'warnings' => $warnings];
    }
}
