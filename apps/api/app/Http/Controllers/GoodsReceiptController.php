<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Purchases\GoodsReceiving;
use App\Domain\Purchases\ReceivedGoods;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 3: goods received notes (069). Seeing them is a purchases right (bills.view); recording or voiding one moves
 * stock and posts to the ledger, so it takes inventory.receive. They are recorded from their purchase order
 * (PurchaseOrderController::receive). Every query is the company's own under row-level security; an id from another
 * company is simply not found.
 */
final class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiving $receiving, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:posted,void'],
            'purchase_order_id' => ['nullable', 'uuid'],
        ]);
        $value = DB::table('goods_receipt_lines')->groupBy('goods_receipt_id')->selectRaw('goods_receipt_id, sum(amount)::numeric(20,2)::text AS value');

        return response()->json(DB::table('goods_receipts as g')
            ->join('documents as o', 'o.id', '=', 'g.purchase_order_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->leftJoinSub($value, 'v', 'v.goods_receipt_id', '=', 'g.id')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('g.status', $status))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where('g.purchase_order_id', $id))
            ->select(['g.id', 'g.number', 'g.received_on', 'g.reference', 'g.status', 'g.purchase_order_id', 'o.number as order_number',
                'c.name as supplier', 'w.name as warehouse', 'v.value'])
            ->orderByDesc('g.received_on')->orderByDesc('g.number')
            ->cursorPaginate(50));
    }

    public function show(string $goodsReceipt): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return $this->present($this->receiving->find($goodsReceipt));
    }

    /** Voids a note: the goods go out again and its entry is reversed. Refused while its goods are billed. */
    public function void(Request $request, string $goodsReceipt): JsonResponse
    {
        $this->authorizeCompany('inventory.receive');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->receiving->find($goodsReceipt);   // 404 for an id that is not the company's

        return $this->present($this->receiving->void($goodsReceipt, $reason, $request->user()->getKey()));
    }

    /** Goods received not invoiced, line by line: what arrived and is not yet billed, at the order's cost. */
    public function unbilled(ReceivedGoods $received): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $lines = $received->outstanding();

        return response()->json(['data' => $lines, 'total' => array_reduce($lines, fn (string $s, array $l) => bcadd($s, (string) $l['value'], 2), '0.00')]);
    }

    /** @param array<string, mixed> $receipt */
    private function present(array $receipt, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$receipt,
            'can' => ['void' => $receipt['status'] === 'posted' && (bool) $this->current->membership()?->can('inventory.receive')],
        ], $status);
    }
}
