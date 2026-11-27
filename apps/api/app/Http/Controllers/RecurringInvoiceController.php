<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Models\Document;
use App\Domain\Sales\RecurringInvoices;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: recurring invoices (077). A template is made from an invoice ("repeat this invoice"): its customer, currency,
 * lines and notes, how often, from when and until when, and whether each invoice is posted or left as a draft. Seeing
 * them takes invoices.view; setting one up, pausing or resuming it invoices.create, and invoices.approve too for one
 * that posts. A template makes its invoices as the person who set it up or last resumed it. Every change is audited.
 */
final class RecurringInvoiceController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit, private readonly RecurringInvoices $recurring)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorizeCompany('invoices.view');
        $m = $this->current->membership();

        return response()->json([
            'data' => DB::table('recurring_invoices as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->leftJoin('users as u', 'u.id', '=', 'r.posts_as')
                ->orderByDesc('r.is_active')->orderBy('r.next_on')
                ->get(['r.id', 'r.name', 'r.contact_id', 'c.name as customer', 'r.currency', 'r.frequency', 'r.next_on', 'r.ends_on', 'r.post', 'r.is_active',
                    'r.made_count', 'r.last_made_on', 'r.last_error', 'u.name as posts_as_name', 'r.lines'])
                ->map(fn ($r) => [...(array) $r, 'lines' => count(json_decode((string) $r->lines, true) ?: [])]),
            'can' => ['manage' => (bool) $m?->can('invoices.create')],
        ]);
    }

    /** "Repeat this invoice": a template from an invoice's customer, currency, lines and notes. */
    public function store(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'min:3', 'max:120'],
            'frequency' => ['required', Rule::in(RecurringInvoices::FREQUENCIES)],
            'next_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $this->current->today()],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:next_on'],
            'post' => ['boolean'],
        ]);
        if ($invoice->type !== 'invoice') {
            throw ValidationException::withMessages(['invoice' => ['Only an invoice is repeated, not a credit note.']]);
        }
        if (($data['post'] ?? false) && ! $this->current->membership()?->can('invoices.approve')) {
            throw ValidationException::withMessages(['post' => ['Your role cannot post invoices: make drafts to approve, or ask someone who can post to set it up.']]);
        }
        $lines = $invoice->lines()->orderBy('line_no')->get()->map(fn ($l) => array_filter([
            'description' => (string) $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
            'discount_rate' => (string) $l->discount_rate, 'account_id' => (string) $l->account_id, 'tax_rate_id' => $l->tax_rate_id,
            'item_id' => $l->item_id, 'project_id' => $l->project_id, 'unit_id' => $l->unit_id, 'class_id' => $l->class_id, 'location_id' => $l->location_id,
        ], fn ($v) => $v !== null))->values()->all();
        $id = (string) Str::uuid7();
        $name = $data['name'] ?? mb_substr(($invoice->contact?->name ?? 'Invoice') . ' · ' . ($lines[0]['description'] ?? 'repeated'), 0, 120);
        $row = [
            'id' => $id, 'company_id' => $invoice->company_id, 'name' => mb_strlen($name) >= 3 ? $name : 'Repeated invoice', 'contact_id' => $invoice->contact_id,
            'currency' => $invoice->currency, 'prices_include_tax' => (bool) $invoice->prices_include_tax, 'out_of_scope' => (bool) $invoice->out_of_scope, 'warehouse_id' => $invoice->warehouse_id,
            'notes' => $invoice->notes, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'frequency' => $data['frequency'], 'next_on' => $data['next_on'],
            'ends_on' => $data['ends_on'] ?? null, 'post' => (bool) ($data['post'] ?? false), 'posts_as' => $request->user()->getKey(),
        ];
        DB::table('recurring_invoices')->insert($row);
        $this->audit->record($this->current->id(), 'recurring_invoice_created', 'recurring_invoice', $id, null,
            [...array_diff_key($row, ['lines' => true, 'company_id' => true, 'id' => true]), 'from_invoice' => $invoice->number ?? $invoice->getKey(), 'lines' => count($lines)],
            userId: $request->user()->getKey());

        return response()->json($this->find($id), 201);
    }

    public function show(string $recurringInvoice): JsonResponse
    {
        $this->authorizeCompany('invoices.view');

        return response()->json($this->find($recurringInvoice));
    }

    /** Paused: it makes nothing until resumed. */
    public function pause(Request $request, string $recurringInvoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $t = $this->find($recurringInvoice);
        if ($t['is_active']) {
            DB::table('recurring_invoices')->where('id', $recurringInvoice)->update(['is_active' => false]);
            $this->audit->record($this->current->id(), 'recurring_invoice_paused', 'recurring_invoice', $recurringInvoice, ['is_active' => true], ['is_active' => false],
                userId: $request->user()->getKey());
        }

        return response()->json($this->find($recurringInvoice));
    }

    /** Resumed by the person signed in, who it then makes invoices as; from the date given, or its next date if that is still ahead. */
    public function resume(Request $request, string $recurringInvoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $data = $request->validate(['next_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . $this->current->today()]]);
        $t = $this->find($recurringInvoice);
        if ($t['post'] && ! $this->current->membership()?->can('invoices.approve')) {
            throw ValidationException::withMessages(['post' => ['It posts its invoices, and your role cannot post: someone who can posts must resume it.']]);
        }
        $next = $data['next_on'] ?? ($t['next_on'] >= $this->current->today() ? $t['next_on'] : $this->current->today());
        if ($t['ends_on'] !== null && $next > $t['ends_on']) {
            throw ValidationException::withMessages(['next_on' => ["It ended on {$t['ends_on']}: set up a new one from an invoice."]]);
        }
        DB::table('recurring_invoices')->where('id', $recurringInvoice)
            ->update(['is_active' => true, 'next_on' => $next, 'last_error' => null, 'posts_as' => $request->user()->getKey()]);
        $this->audit->record($this->current->id(), 'recurring_invoice_resumed', 'recurring_invoice', $recurringInvoice,
            ['is_active' => $t['is_active'], 'next_on' => $t['next_on']], ['is_active' => true, 'next_on' => $next], userId: $request->user()->getKey());

        return response()->json($this->find($recurringInvoice));
    }

    /** Makes what has come due now, as the template's own person, as the hourly run would. */
    public function makeDue(Request $request, string $recurringInvoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        $t = $this->find($recurringInvoice);
        $result = $this->recurring->makeDue($this->current->get(), $recurringInvoice, (string) $t['posts_as'], $this->current->today());

        return response()->json([...$this->find($recurringInvoice), 'made' => $result['made'], 'error' => $result['error']]);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $t = DB::table('recurring_invoices as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->where('r.id', $id)
            ->first(['r.*', 'c.name as customer']) ?? abort(404);
        $invoices = Document::query()->where('recurring_invoice_id', $id)->orderByDesc('issue_date')->limit(50)
            ->get(['id', 'number', 'issue_date', 'status', 'total']);

        return [...(array) $t, 'is_active' => (bool) $t->is_active, 'post' => (bool) $t->post, 'next_on' => (string) $t->next_on,
            'ends_on' => $t->ends_on !== null ? (string) $t->ends_on : null, 'lines' => json_decode((string) $t->lines, true), 'invoices' => $invoices];
    }
}
