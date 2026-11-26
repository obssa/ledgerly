<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Reservations;
use App\Domain\Purchases\GoodsReceiving;
use App\Domain\Purchases\ReceivedGoods;
use App\Domain\Inventory\Models\Item;
use App\Domain\Sales\Actions\QuoteWorkflow;
use App\Domain\Sales\Actions\SaveQuote;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Requests\SaveBillRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Module 3: purchase orders (068). What the company asked a supplier for: drafted, issued (numbered PO-), revised
 * with history, billed in parts as the supplier's invoices come in, closed with the rest dropped, or cancelled while
 * nothing is billed. They are purchase documents, so the bill permissions apply: bills.view to see them,
 * bills.create for everything else. Nothing here posts but receiving goods (069, inventory.receive: stock in at the
 * order's cost against goods received not invoiced); the bills made from an order post as any bill.
 */
final class PurchaseOrderController extends Controller
{
    use Concerns\FiltersByDate;

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly QuoteWorkflow $workflow,
        private readonly ReceivedGoods $received,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $range = $this->dateRange($request);
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,accepted,converted,void'],
            'contact_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(Document::query()
            ->where('type', 'purchase_order')
            ->with('contact:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->when(true, fn ($q) => $this->withinDates($q, 'issue_date', $range))   // the period asked for (FiltersByDate)
            ->orderBy('issue_date', 'desc')->orderBy('id')
            ->cursorPaginate(50));
    }

    public function store(SaveBillRequest $request, SaveQuote $save): JsonResponse
    {
        $this->authorizeCompany('bills.create');

        return $this->present($save->handle($request->validated(), 'purchase_order', null, $request->user()->getKey()), 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return $this->present($document);
    }

    /** A draft is saved; an issued order is revised, its earlier version kept with the reason, until a bill is made from it. */
    public function update(SaveBillRequest $request, Document $document, SaveQuote $save): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->present($save->handle([...$request->validated(), 'reason' => $reason], 'purchase_order', $document, $request->user()->getKey()));
    }

    /** Issued to the supplier: it takes its number. */
    public function issue(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.create');

        return $this->present($this->workflow->accept($document, $request->user()->getKey()));
    }

    public function cancel(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->present($this->workflow->cancel($document, $request->user()->getKey(), $note));
    }

    /** Makes a draft bill for what is given in lines (order line and quantity), or for all that is left. */
    public function bill(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $data = $request->validate([
            'lines' => ['nullable', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'min:0'],
        ]);
        $billed = isset($data['lines']) ? collect($data['lines'])->mapWithKeys(fn (array $l) => [$l['line_id'] => (string) $l['quantity']])->all() : null;
        $made = $this->workflow->convert($document, 'bill', $request->user()->getKey(), null, $billed);

        return response()->json(['id' => $made->getKey(), 'type' => $made->type, 'number' => $made->number, 'status' => $made->status], 201);
    }

    /** 069: the goods that arrived (order line and quantity), on a goods received note. */
    public function receive(Request $request, Document $document, GoodsReceiving $receiving): JsonResponse
    {
        $this->authorizeCompany('inventory.receive');
        $data = $request->validate([
            'received_on' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'min:0'],
            'lines.*.batch' => ['nullable', 'string', 'max:2000'],   // 074: for an item tracked by batch
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $quantities = collect($data['lines'])->mapWithKeys(fn (array $l) => [$l['line_id'] => (string) $l['quantity']])->all();
        $batches = collect($data['lines'])->filter(fn (array $l) => ($l['batch'] ?? null) !== null)
            ->mapWithKeys(fn (array $l) => [$l['line_id'] => ['number' => $l['batch'], 'expires_on' => $l['expires_on'] ?? null]])->all();

        return response()->json($receiving->receive($document, $quantities, $data['received_on'], $data['warehouse_id'] ?? null,
            $data['reference'] ?? null, $data['note'] ?? null, $request->user()->getKey(), $batches), 201);
    }

    /** Nothing more is coming: what is left is dropped from the order. */
    public function close(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.create');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->present($this->workflow->close($document, $request->user()->getKey(), $note));
    }

    /** What the issued purchase orders still wait for, line by line, and for stock items what is on hand and on order. */
    public function open(Reservations $reservations): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $lines = $reservations->backOrders('purchase_order');
        $items = array_values(array_unique(array_filter(array_map(fn (array $l) => $l['item_type'] === 'inventory' ? $l['item_id'] : null, $lines))));

        return response()->json(['data' => $lines, 'stock' => $items === [] ? (object) [] : $reservations->byItem($items)]);
    }

    /** Every earlier version and the audit trail, newest first. */
    public function history(Document $document): JsonResponse
    {
        $this->authorizeCompany('bills.view');

        return response()->json([
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->orderByDesc('version')->get(),
            'audit' => DB::table('audit_log')->where('company_id', $document->company_id)->where('subject_id', $document->getKey())
                ->orderByDesc('id')->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']),
        ]);
    }

    private function present(Document $document, int $status = 200): JsonResponse
    {
        $document->load(['lines', 'contact:id,name,tax_id,email']);
        $write = (bool) $this->current->membership()?->can('bills.create');
        $s = $document->status;
        $bills = Document::query()->where('source_document_id', $document->getKey())->orderBy('issue_date')->orderBy('id')
            ->get(['id', 'number', 'reference', 'issue_date', 'status', 'total']);
        $live = $bills->contains(fn (Document $b) => $b->status !== 'void');
        $delivery = $this->workflow->remaining($document);
        $left = collect($delivery)->contains(fn (array $r) => bccomp($r['remaining'], '0', 2) > 0);
        // 069: what arrived on goods received notes, what of it is billed, and what can be billed or received now.
        $received = $this->received->byLine((string) $document->getKey());
        $billable = $this->workflow->billable($document, $delivery);
        $receipts = DB::table('goods_receipts as g')->join('warehouses as w', 'w.id', '=', 'g.warehouse_id')
            ->leftJoinSub(DB::table('goods_receipt_lines')->groupBy('goods_receipt_id')->selectRaw('goods_receipt_id, sum(amount)::numeric(20,2)::text AS value'),
                'v', 'v.goods_receipt_id', '=', 'g.id')
            ->where('g.purchase_order_id', $document->getKey())->orderBy('g.received_on')->orderBy('g.number')
            ->get(['g.id', 'g.number', 'g.received_on', 'g.reference', 'g.status', 'w.name as warehouse', 'v.value']);
        $liveReceipts = $receipts->contains(fn ($r) => $r->status === 'posted');
        $stock = Item::query()->whereIn('id', $document->lines->pluck('item_id')->filter())->where('type', 'inventory')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $billedIn = DB::table('stock_moves as m')->join('documents as b', 'b.id', '=', 'm.document_id')
            ->where(['m.kind' => 'purchase', 'b.type' => 'bill', 'b.source_document_id' => $document->getKey()])->where('b.status', '<>', 'void')
            ->distinct()->pluck('m.item_id')->map(fn ($id) => (string) $id)->all();
        $arriving = [];
        foreach ($document->lines as $line) {
            $id = (string) $line->getKey();
            if (in_array((string) $line->item_id, $stock, true) && ! in_array((string) $line->item_id, $billedIn, true)) {
                $toCome = bcsub((string) $line->quantity, $received[$id]['received'] ?? '0', 2);
                if (bccomp($toCome, '0', 2) > 0) {
                    $arriving[$id] = $toCome;
                }
            }
            $delivery[$id] = [...$delivery[$id], 'received' => $received[$id]['received'] ?? '0.00', 'billable' => $billable[$id]['remaining']];
        }
        $unbilled = collect($received)->contains(fn (array $r, string $id) => bccomp($r['received'], $delivery[$id]['delivered'] ?? '0', 2) > 0);
        $mayReceive = (bool) $this->current->membership()?->can('inventory.receive');

        return response()->json([
            ...$document->toArray(),
            // 068: each order line's ordered, billed and remaining quantities (by line id), and the bills made from it;
            // 069: with what was received, and what can be billed now; the goods received notes; what can still arrive.
            'delivery' => $delivery,
            'bills' => $bills,
            'receipts' => $receipts,
            'arriving' => (object) $arriving,
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->count(),
            'can' => [
                'edit' => $write && in_array($s, SaveQuote::EDITABLE['purchase_order'], true) && $bills->isEmpty() && $receipts->isEmpty(),
                'issue' => $write && $s === 'draft',
                'bill' => $write && $s === 'accepted' && collect($billable)->contains(fn (array $r) => bccomp($r['remaining'], '0', 2) > 0),
                'receive' => $mayReceive && $s === 'accepted' && $arriving !== [],
                'close' => $write && $s === 'accepted' && $live && ! $unbilled,
                'cancel' => $write && in_array($s, ['draft', 'accepted'], true) && ! $live && ! $liveReceipts,
            ],
        ], $status);
    }
}
