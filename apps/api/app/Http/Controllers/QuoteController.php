<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
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

    /** Makes the draft invoice or sales order; answers with it (its type says which). */
    public function convert(Request $request, Document $document): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate([
            'to' => ['required', 'in:invoice,sales_order'],
            'number' => ['nullable', 'string', 'max:40'],   // manual numbering only
        ]);
        $made = $this->workflow->convert($document, $data['to'], $request->user()->getKey(), $data['number'] ?? null);

        return response()->json(['id' => $made->getKey(), 'type' => $made->type, 'number' => $made->number, 'status' => $made->status], 201);
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
        $source = $document->source_document_id ? Document::query()->find($document->source_document_id, ['id', 'type', 'number']) : null;

        return response()->json([
            ...$document->toArray(),
            'salesperson' => $document->salesperson_id ? DB::table('users')->where('id', $document->salesperson_id)->first(['id', 'name']) : null,
            'source' => $source?->only(['id', 'type', 'number']),
            'made' => $made?->only(['id', 'type', 'number', 'status']),
            'versions' => DocumentVersion::query()->where('document_id', $document->getKey())->count(),
            'can' => [
                'edit' => $write && in_array($s, SaveQuote::EDITABLE[$document->type], true),
                'send' => $write && $quote && $s === 'draft',
                'accept' => $write && ($quote ? in_array($s, ['draft', 'sent'], true) : $s === 'draft'),
                'decline' => $write && $quote && $s === 'sent',
                'extend' => $write && $quote && $s === 'expired',
                'cancel' => $write && in_array($s, ['draft', 'sent', 'accepted'], true),
                'convert' => $write && in_array($s, $quote ? ['draft', 'sent', 'accepted'] : ['draft', 'accepted'], true),
                'email' => $write && ($quote ? in_array($s, ['draft', 'sent', 'accepted'], true) : $s === 'accepted'),
            ],
        ], $status);
    }
}
