<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\DeliveredGoods;
use App\Domain\Sales\Delivering;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 2: delivery notes (071). Seeing them is a sales right (invoices.view); recording or voiding one moves stock and
 * posts the cost of goods sold, so it takes inventory.deliver. A note is recorded from its sales order. Every query is
 * the company's own under row-level security; an id from another company is simply not found.
 */
final class DeliveryNoteController extends Controller
{
    use Concerns\FiltersByDate;

    public function __construct(private readonly Delivering $delivering, private readonly CurrentCompany $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $range = $this->dateRange($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:posted,void'],
            'sales_order_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(DB::table('delivery_notes as d')
            ->join('documents as o', 'o.id', '=', 'd.sales_order_id')
            ->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('d.status', $status))
            ->when($filters['sales_order_id'] ?? null, fn ($q, $id) => $q->where('d.sales_order_id', $id))
            ->select(['d.id', 'd.number', 'd.delivered_on', 'd.reference', 'd.status', 'd.sales_order_id', 'o.number as order_number',
                'c.name as customer', 'w.name as warehouse'])
            ->when(true, fn ($q) => $this->withinDates($q, 'd.delivered_on', $range))   // the period asked for (FiltersByDate)
            ->orderByDesc('d.delivered_on')->orderByDesc('d.number')
            ->cursorPaginate(50));
    }

    public function show(string $deliveryNote): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return $this->present($this->delivering->find($deliveryNote));
    }

    /** 071: the goods that left (order line and quantity), on a delivery note. */
    public function deliver(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('inventory.deliver');
        $data = $request->validate([
            'delivered_on' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'min:0'],
        ]);
        $quantities = collect($data['lines'])->mapWithKeys(fn (array $l) => [$l['line_id'] => (string) $l['quantity']])->all();

        return $this->present($this->delivering->deliver($document, $quantities, $data['delivered_on'], $data['warehouse_id'] ?? null,
            $data['reference'] ?? null, $data['note'] ?? null, $request->user()->getKey()), 201);
    }

    /** Voids a note: the goods come back and its entry is reversed. Refused while its goods are invoiced. */
    public function void(Request $request, string $deliveryNote): JsonResponse
    {
        $this->authorizeCompany('inventory.deliver');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->delivering->find($deliveryNote);   // 404 for an id that is not the company's

        return $this->present($this->delivering->void($deliveryNote, $reason, $request->user()->getKey()));
    }

    /** Goods delivered not invoiced, line by line: what left and is not yet on an invoice. */
    public function uninvoiced(DeliveredGoods $delivered): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json(['data' => $delivered->outstanding()]);
    }

    /** @param array<string, mixed> $note */
    private function present(array $note, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$note,
            'can' => ['void' => $note['status'] === 'posted' && (bool) $this->current->membership()?->can('inventory.deliver')],
        ], $status);
    }
}
