<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Reservations;
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
 * bills.create for everything else. Nothing here posts; the bills made from an order post as any bill.
 */
final class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly QuoteWorkflow $workflow,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompany('bills.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,accepted,converted,void'],
            'contact_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(Document::query()
            ->where('type', 'purchase_order')
            ->with('contact:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
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

        return response()->json([
            ...$document->toArray(),
            // 068: each order line's ordered, billed and remaining quantities (by line id), and the bills made from it.
            'delivery' => $delivery,
            'bills' => $bills,
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->count(),
            'can' => [
                'edit' => $write && in_array($s, SaveQuote::EDITABLE['purchase_order'], true) && $bills->isEmpty(),
                'issue' => $write && $s === 'draft',
                'bill' => $write && $s === 'accepted' && $left,
                'close' => $write && $s === 'accepted' && $live,
                'cancel' => $write && in_array($s, ['draft', 'accepted'], true) && ! $live,
            ],
        ], $status);
    }
}
