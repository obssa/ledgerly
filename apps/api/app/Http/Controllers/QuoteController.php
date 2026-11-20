<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Reservations;
use App\Domain\Sales\DeliveredGoods;
use App\Domain\Sales\Actions\QuoteWorkflow;
use App\Domain\Sales\Actions\SaveQuote;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\Models\DocumentVersion;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Http\Requests\SaveInvoiceRequest;
use App\Notifications\InvoiceSent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: quotes and sales orders (056). The same routes serve both kinds: index and store name the kind in the
 * route; every other route finds only its own kind (routes/api.php). They are sales documents, so the invoice
 * permissions apply: invoices.view to see them, invoices.create for everything else. Nothing here posts.
 */
final class QuoteController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $current,
        private readonly QuoteWorkflow $workflow,
    ) {
    }

    public function index(Request $request, string $kind): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,sent,accepted,declined,expired,converted,void'],
            'contact_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(Document::query()
            ->where('type', $kind)
            ->with('contact:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->orderBy('issue_date', 'desc')->orderBy('id')
            ->cursorPaginate(50));
    }

    public function store(SaveInvoiceRequest $request, string $kind, SaveQuote $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return $this->present($save->handle($request->validated(), $kind, null, $request->user()->getKey()), 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return $this->present($document);
    }

    /** A draft is saved; a quote sent or an order confirmed is revised, its earlier version kept with the reason. */
    public function update(SaveInvoiceRequest $request, Document $document, SaveQuote $save): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->present($save->handle([...$request->validated(), 'reason' => $reason], (string) $document->type, $document, $request->user()->getKey()));
    }

    public function send(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return $this->present($this->workflow->send($document, $request->user()->getKey()));
    }

    /** A quote accepted by the customer, or a sales order confirmed. */
    public function accept(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');

        return $this->present($this->workflow->accept($document, $request->user()->getKey()));
    }

    public function decline(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->present($this->workflow->decline($document, $request->user()->getKey(), $note));
    }

    public function extend(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $date = $request->validate(['valid_until' => ['required', 'date_format:Y-m-d']])['valid_until'];

        return $this->present($this->workflow->extend($document, $date, $request->user()->getKey()));
    }

    public function cancel(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->present($this->workflow->cancel($document, $request->user()->getKey(), $note));
    }

    /**
     * Makes the draft invoice or sales order; answers with it (its type says which). An order's invoice takes what is
     * given in lines (order line and quantity; a line left out or at 0 is not delivered now), or all that is left.
     */
    public function convert(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate([
            'to' => ['required', 'in:invoice,sales_order'],
            'number' => ['nullable', 'string', 'max:40'],   // manual numbering only
            'lines' => ['nullable', 'array', 'min:1', 'max:500'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'min:0'],
        ]);
        $deliver = isset($data['lines']) ? collect($data['lines'])->mapWithKeys(fn (array $l) => [$l['line_id'] => (string) $l['quantity']])->all() : null;
        $made = $this->workflow->convert($document, $data['to'], $request->user()->getKey(), $data['number'] ?? null, $deliver);

        return response()->json(['id' => $made->getKey(), 'type' => $made->type, 'number' => $made->number, 'status' => $made->status], 201);
    }

    /** A sales order partly delivered is closed: the rest is dropped from its back-order. */
    public function close(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->present($this->workflow->close($document, $request->user()->getKey(), $note));
    }

    /** What the confirmed orders still owe, line by line, and for the stock items in them what is on hand and reserved. */
    public function backOrders(Reservations $reservations): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $lines = $reservations->backOrders();
        $items = array_values(array_unique(array_filter(array_map(fn (array $l) => $l['item_type'] === 'inventory' ? $l['item_id'] : null, $lines))));

        return response()->json(['data' => $lines, 'stock' => $items === [] ? (object) [] : $reservations->byItem($items)]);
    }

    /** Every earlier version (what the customer saw before a revision) and the audit trail, newest first. */
    public function history(Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json([
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->orderByDesc('version')->get(),
            'audit' => DB::table('audit_log')->where('company_id', $document->company_id)->where('subject_id', $document->getKey())
                ->orderByDesc('id')->get(['id', 'action', 'user_id', 'actor_type', 'before', 'after', 'reason', 'ip', 'created_at']),
        ]);
    }

    /** The quote or order emailed to the customer, with its lines and totals; a draft quote is sent (numbered) first. */
    public function email(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        if (! in_array($document->status, ['draft', 'sent', 'accepted'], true) || ($document->type === 'sales_order' && $document->status === 'draft')) {
            throw ValidationException::withMessages(['document' => [$document->type === 'sales_order'
                ? 'Only a confirmed order is sent: confirm it first.' : "This quote is {$document->status}; it is not sent again."]]);
        }
        $company = $this->current->get();
        $contact = DB::table('contacts')->where(['company_id' => $company->getKey(), 'id' => $document->contact_id])->first(['name', 'email']);
        $to = trim((string) ($contact->email ?? ''));
        if ($to === '') {
            throw ValidationException::withMessages(['document' => ['The customer has no email address: add one to send it.']]);
        }
        $key = 'quote-email:' . $document->getKey();
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['document' => ['It was sent a moment ago: wait a few minutes before sending it again.']]);
        }
        RateLimiter::hit($key, 300);

        if ($document->status === 'draft') {
            $document = $this->workflow->send($document, $request->user()->getKey());
        }
        $document->load('lines');
        $money = fn ($v) => number_format((float) $v, 2);
        // A sent quote carries a link to see it and answer it online (065); a new email replaces the last link.
        $link = null;
        if ($document->type === 'quote' && $document->status === 'sent') {
            $token = bin2hex(random_bytes(32));
            $until = $document->due_date !== null ? \Illuminate\Support\Carbon::parse($document->due_date->format('Y-m-d') . ' 23:59:59', $company->timezone ?: 'UTC') : now()->addDays(30);
            $expires = $until->max(now()->addDay())->min(now()->addDays(90));
            DB::select('SELECT quote_link_issue(?, ?, ?)', [$document->getKey(), hash('sha256', $token), $expires->toIso8601String()]);
            $link = rtrim((string) config('security.frontend_url'), '/') . '/quote?token=' . $token;
        }
        Notification::route('mail', [$to => (string) $contact->name])->notify(new InvoiceSent(
            company: (string) ($company->trading_name ?: $company->legal_name), companyTin: $company->tin ? (string) $company->tin : null,
            replyTo: $company->email ? (string) $company->email : null, customer: (string) $contact->name, number: (string) $document->number,
            kind: (string) $document->type, issueDate: $document->issue_date->format('j M Y'), dueDate: $document->due_date?->format('j M Y'), currency: (string) $document->currency,
            lines: $document->lines->map(fn ($l) => ['description' => (string) $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'),
                'unit_price' => $money($l->unit_price), 'amount' => $money(bcadd((string) $l->line_subtotal, (string) $l->line_tax, 2))])->values()->all(),
            subtotal: $money($document->subtotal), tax: $money($document->tax_total), total: $money($document->total), amountDue: $money($document->total),
            clearance: null, note: $data['note'] ?? null, link: $link,
        ));
        app(AuditLogger::class)->record($company->getKey(), "{$document->type}_emailed", (string) $document->type, $document->getKey(), null,
            ['sent_to' => $to, 'number' => $document->number], userId: $request->user()->getKey());

        return response()->json(['sent_to' => $to, 'status' => $document->status, 'number' => $document->number]);
    }

    /** The document, its customer and lines, what it came from and what was made from it, and what the member may do now. */
    private function present(Document $document, int $status = 200): JsonResponse
    {
        $document->load(['lines', 'contact:id,name,tax_id,email']);
        $write = (bool) $this->current->membership()?->can('invoices.create');
        $quote = $document->type === 'quote';
        $s = $document->status;
        $made = Document::query()->where('source_document_id', $document->getKey())->where('status', '<>', 'void')->first(['id', 'type', 'number', 'status']);
        $order = $document->type === 'sales_order';
        $delivery = $order ? $this->workflow->remaining($document) : null;
        $invoices = $order ? Document::query()->where('source_document_id', $document->getKey())->orderBy('issue_date')->orderBy('id')
            ->get(['id', 'number', 'issue_date', 'status', 'total']) : null;
        $left = $order && collect($delivery)->contains(fn (array $r) => bccomp($r['remaining'], '0', 2) > 0);
        // 071: what left on delivery notes, what can be invoiced now, the notes, and the stock lines that can still leave.
        $notes = collect();
        $leaving = [];
        $uninvoiced = false;
        if ($order) {
            $byNote = app(DeliveredGoods::class)->byLine((string) $document->getKey());
            $billable = $this->workflow->billable($document, $delivery);
            $notes = DB::table('delivery_notes as d')->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
                ->where('d.sales_order_id', $document->getKey())->orderBy('d.delivered_on')->orderBy('d.number')
                ->get(['d.id', 'd.number', 'd.delivered_on', 'd.reference', 'd.status', 'w.name as warehouse']);
            $kinds = DB::table('items')->whereIn('id', $document->lines->pluck('item_id')->filter())->whereIn('type', ['inventory', 'bundle'])->pluck('type', 'id');
            $parts = app(DeliveredGoods::class)->stockParts($kinds->filter(fn ($t) => $t === 'bundle')->keys()->map(fn ($id) => (string) $id)->values()->all());   // 097
            $invoicedOut = DB::table('stock_moves as m')->join('documents as i', 'i.id', '=', 'm.document_id')
                ->where(['m.kind' => 'sale', 'i.type' => 'invoice', 'i.source_document_id' => $document->getKey()])->where('i.status', '<>', 'void')
                ->distinct()->pluck('m.item_id')->map(fn ($id) => (string) $id)->all();
            foreach ($document->lines as $line) {
                $id = (string) $line->getKey();
                $shipped = $byNote[$id]['delivered'] ?? '0.00';
                $kind = $kinds[(string) $line->item_id] ?? null;
                $taken = $kind === 'bundle' ? array_column($parts[(string) $line->item_id] ?? [], 'item_id') : [(string) $line->item_id];
                if ($kind !== null && $taken !== [] && array_intersect($taken, $invoicedOut) === []) {
                    $toGo = bcsub((string) $line->quantity, $shipped, 2);
                    if (bccomp($toGo, '0', 2) > 0) {
                        $leaving[$id] = $toGo;
                    }
                }
                $uninvoiced = $uninvoiced || bccomp($shipped, $delivery[$id]['delivered'] ?? '0', 2) > 0;
                $delivery[$id] = [...$delivery[$id], 'shipped' => $shipped, 'billable' => $billable[$id]['remaining']];
            }
            $left = collect($billable)->contains(fn (array $r) => bccomp($r['remaining'], '0', 2) > 0);
        }
        $source = $document->source_document_id ? Document::query()->find($document->source_document_id, ['id', 'type', 'number']) : null;

        return response()->json([
            ...$document->toArray(),
            'salesperson' => $document->salesperson_id ? DB::table('users')->where('id', $document->salesperson_id)->first(['id', 'name']) : null,
            'source' => $source?->only(['id', 'type', 'number']),
            'made' => $made?->only(['id', 'type', 'number', 'status']),
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->count(),
            // 067: each order line's ordered, delivered and remaining quantities (by line id), and the invoices made.
            'delivery' => $delivery,
            'invoices' => $invoices,
            // 071: the order's delivery notes, and its stock lines that can still leave (by line id).
            'delivery_notes' => $order ? $notes : null,
            'leaving' => $order ? (object) $leaving : null,
            'can' => [
                'edit' => $write && in_array($s, SaveQuote::EDITABLE[$document->type], true) && ! ($order && ($invoices->isNotEmpty() || $notes->isNotEmpty())),
                'send' => $write && $quote && $s === 'draft',
                'accept' => $write && ($quote ? in_array($s, ['draft', 'sent'], true) : $s === 'draft'),
                'decline' => $write && $quote && $s === 'sent',
                'extend' => $write && $quote && $s === 'expired',
                'cancel' => $write && in_array($s, ['draft', 'sent', 'accepted'], true) && ! ($order && ($made !== null || $notes->contains(fn ($n) => $n->status === 'posted'))),
                'deliver' => $order && $s === 'accepted' && $leaving !== [] && (bool) $this->current->membership()?->can('inventory.deliver'),
                'convert' => $write && in_array($s, $quote ? ['draft', 'sent', 'accepted'] : ['draft', 'accepted'], true) && ($quote || $left),
                'close' => $write && $order && $s === 'accepted' && $made !== null && ! $uninvoiced,
                'email' => $write && ($quote ? in_array($s, ['draft', 'sent', 'accepted'], true) : $s === 'accepted'),
            ],
        ], $status);
    }
}
