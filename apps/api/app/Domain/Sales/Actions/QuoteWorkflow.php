<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Exceptions\LedgerException;
use App\Domain\Sales\DocumentNumbers;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: what happens to a quote or a sales order after it is saved (056, the quote machine of 006). A quote
 * takes its number when it is sent (or accepted straight from a draft), a sales order when it is confirmed. Every
 * move is audited; none of them posts anything.
 */
final class QuoteWorkflow
{
    /** The documents delivered or billed in parts (067, 068). */
    public const ORDERS = ['sales_order', 'purchase_order'];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly DocumentNumbers $numbers,
        private readonly SaveInvoiceDraft $drafts,
        private readonly SaveQuote $quotes,
        private readonly AuditLogger $audit,
    ) {
    }

    /** A quote goes to the customer. */
    public function send(Document $quote, string $userId): Document
    {
        return $this->move($quote, 'quote', ['draft'], 'sent', 'quote_sent', $userId);
    }

    /** The customer accepted the quote; a sales order is confirmed. */
    public function accept(Document $document, string $userId): Document
    {
        return $this->move($document, (string) $document->type, $document->type === 'quote' ? ['draft', 'sent'] : ['draft'], 'accepted',
            match ($document->type) { 'quote' => 'quote_accepted', 'purchase_order' => 'purchase_order_issued', default => 'sales_order_confirmed' }, $userId);
    }

    /**
     * The customer's own answer through the quote's link (065): accepted or declined while it is sent and not past its
     * date, recorded with their name (no member of the company did it).
     */
    public function answerOnline(Document $quote, bool $accept, string $name, ?string $note = null): Document
    {
        if ($quote->due_date !== null && $quote->due_date->format('Y-m-d') < $this->current->today()) {
            throw new LedgerException('This quote was valid until ' . $quote->due_date->format('j M Y') . ': ask the company for a new one.');
        }
        $reason = ($accept ? 'Accepted' : 'Declined') . " online by {$name}" . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : '');

        return $this->move($quote, 'quote', ['sent'], $accept ? 'accepted' : 'declined', $accept ? 'quote_accepted_online' : 'quote_declined_online',
            null, mb_substr($reason, 0, 500), [], 'system');
    }

    public function decline(Document $quote, string $userId, ?string $note = null): Document
    {
        return $this->move($quote, 'quote', ['sent'], 'declined', 'quote_declined', $userId, $note);
    }

    /** An expired quote is offered again until a new date. */
    public function extend(Document $quote, string $validUntil, string $userId): Document
    {
        if ($validUntil < $this->current->today()) {
            throw ValidationException::withMessages(['valid_until' => ['The new date is today or later.']]);
        }

        return $this->move($quote, 'quote', ['expired'], 'sent', 'quote_extended', $userId, null, ['due_date' => $validUntil]);
    }

    public function cancel(Document $document, string $userId, ?string $note = null): Document
    {
        if (in_array($document->type, self::ORDERS, true) && $this->invoiced($document)) {
            $made = $document->type === 'purchase_order' ? 'bills' : 'invoices';
            throw new LedgerException(SaveQuote::noun((string) $document->type) . " {$document->number} has {$made} made from it: close it instead, and what is left is dropped.");
        }

        return $this->move($document, (string) $document->type, ['draft', 'sent', 'accepted'], 'void', "{$document->type}_cancelled", $userId, $note);
    }

    /** The daily expiry: a sent quote past its valid-until date in the company's own today. */
    public function expire(Document $quote): bool
    {
        return DB::transaction(function () use ($quote) {
            $quote = Document::query()->lockForUpdate()->findOrFail($quote->getKey());
            if ($quote->type !== 'quote' || $quote->status !== 'sent' || $quote->due_date === null || $quote->due_date->format('Y-m-d') >= $this->current->today()) {
                return false;
            }
            $quote->status = 'expired';
            $quote->save();
            $this->audit->record($this->current->id(), 'quote_expired', 'quote', $quote->getKey(), ['status' => 'sent'], ['status' => 'expired'], actorType: 'system');

            return true;
        });
    }

    /**
     * A confirmed order partly delivered is closed: what is left is no longer owed to the customer (nor reserved).
     */
    public function close(Document $order, string $userId, ?string $note = null): Document
    {
        if (! in_array($order->type, self::ORDERS, true) || ! $this->invoiced($order)) {
            throw new LedgerException('Only an order with an invoice or bill made from it is closed; cancel one that has none.');
        }

        return $this->move($order, (string) $order->type, ['accepted'], 'converted', "{$order->type}_closed", $userId, $note);
    }

    /**
     * What is left to deliver of each line of a sales order: ordered less what its live invoices deliver (067).
     *
     * @return array<string, array{ordered: string, delivered: string, remaining: string}> by order line id
     */
    public function remaining(Document $order): array
    {
        $delivered = DB::table('document_lines as l')->join('documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.source_document_id', $order->getKey())->where('d.status', '<>', 'void')->whereNotNull('l.source_line_id')
            ->groupBy('l.source_line_id')->selectRaw('l.source_line_id, sum(l.quantity)::numeric(18,2)::text AS quantity')
            ->pluck('quantity', 'source_line_id');
        $out = [];
        foreach ($order->lines()->orderBy('line_no')->get(['id', 'quantity']) as $line) {
            $done = (string) ($delivered[$line->getKey()] ?? '0.00');
            $left = bcsub((string) $line->quantity, $done, 2);
            $out[(string) $line->getKey()] = ['ordered' => (string) $line->quantity, 'delivered' => $done,
                'remaining' => bccomp($left, '0', 2) > 0 ? $left : '0.00'];
        }

        return $out;
    }

    /**
     * Makes a draft invoice (from a quote or an order) or a draft sales order (from a quote) with the same customer,
     * currency, prices and lines, dated today; the quote or order is accepted first if it was not yet. A quote is then
     * converted. An order is invoiced for what is given in $deliver (order line id => quantity), or for all that is
     * left, and is converted once nothing is left: the rest is its back-order (067). With manual numbering the new
     * document's number is given here.
     *
     * @param array<string, string>|null $deliver
     */
    public function convert(Document $source, string $to, string $userId, ?string $number = null, ?array $deliver = null): Document
    {
        $targets = match ($source->type) { 'quote' => ['invoice', 'sales_order'], 'purchase_order' => ['bill'], default => ['invoice'] };
        if (! in_array($to, $targets, true)) {
            throw ValidationException::withMessages(['to' => ['A quote becomes a sales order or an invoice, a sales order an invoice, a purchase order a bill.']]);
        }
        if ($deliver !== null && ! in_array($source->type, self::ORDERS, true)) {
            throw ValidationException::withMessages(['lines' => ['Only a sales order is delivered in parts.']]);
        }

        return DB::transaction(function () use ($source, $to, $userId, $number, $deliver) {
            $source = Document::query()->lockForUpdate()->with('lines')->findOrFail($source->getKey());
            if (in_array($source->status, ['draft', 'sent'], true)) {
                $source = $this->accept($source, $userId)->load('lines');
            }
            if ($source->status !== 'accepted') {
                throw new LedgerException(SaveQuote::noun((string) $source->type) . " {$source->number} is {$source->status}; it cannot be converted.");
            }

            $lines = $source->lines->sortBy('line_no')->values();
            $quantities = [];
            if (in_array($source->type, self::ORDERS, true)) {
                $left = $this->remaining($source);
                $want = $deliver ?? array_map(fn (array $r) => $r['remaining'], $left);
                foreach ($want as $lineId => $quantity) {
                    if (! isset($left[$lineId])) {
                        throw ValidationException::withMessages(['lines' => ['A line given is not a line of this order.']]);
                    }
                    if (bccomp((string) $quantity, $left[$lineId]['remaining'], 2) > 0) {
                        $no = $lines->firstWhere('id', $lineId)?->line_no;
                        throw ValidationException::withMessages(['lines' => ["Line {$no}: only {$left[$lineId]['remaining']} is left to deliver."]]);
                    }
                }
                $quantities = array_filter($want, fn ($q) => bccomp((string) $q, '0', 2) > 0);
                if ($quantities === []) {
                    throw ValidationException::withMessages(['lines' => ['Nothing is left to deliver on this order.']]);
                }
                $lines = $lines->filter(fn ($l) => isset($quantities[(string) $l->getKey()]))->values();
            }

            $today = $this->current->today();
            $terms = (int) (DB::table('contacts')->where('id', $source->contact_id)->value('payment_terms_days') ?? 0);
            $data = [
                'contact_id' => $source->contact_id,
                'number' => $number,
                'issue_date' => $today,
                'due_date' => $to !== 'sales_order'
                    ? (new DateTimeImmutable($today))->modify("+{$terms} days")->format('Y-m-d')
                    : ($source->due_date !== null && $source->due_date->format('Y-m-d') >= $today ? $source->due_date->format('Y-m-d') : null),
                'currency' => $source->currency,
                'fx_rate' => $source->currency === $this->current->get()->base_currency ? null : (string) $source->fx_rate,
                'prices_include_tax' => (bool) $source->prices_include_tax,
                'notes' => $source->notes,
                'warehouse_id' => $source->warehouse_id,
                'salesperson_id' => $source->salesperson_id,
                'reference' => null,
                'lines' => $lines->map(fn ($l) => [
                    'description' => (string) $l->description,
                    'quantity' => (string) ($quantities[(string) $l->getKey()] ?? $l->quantity), 'unit_price' => (string) $l->unit_price,
                    'discount_rate' => (string) $l->discount_rate, 'account_id' => (string) $l->account_id, 'tax_rate_id' => $l->tax_rate_id,
                    'item_id' => $l->item_id, 'project_id' => $l->project_id,
                ])->values()->all(),
            ];

            if ($to !== 'sales_order') {
                $made = $this->drafts->handle($data, null, $userId, newType: $to);
                $made->forceFill(['source_document_id' => $source->getKey()])->save();   // 056: named once, while a draft
                if (in_array($source->type, self::ORDERS, true)) {
                    // 067, 068: each invoice or bill line names the order line it delivers or bills, in the order they were made.
                    foreach ($made->lines()->orderBy('line_no')->get() as $i => $line) {
                        $line->forceFill(['source_line_id' => (string) $lines[$i]->getKey()])->save();
                    }
                }
            } else {
                $made = $this->quotes->handle($data, 'sales_order', null, $userId, $source);
            }

            $done = ! in_array($source->type, self::ORDERS, true)
                || collect($this->remaining($source))->every(fn (array $r) => bccomp($r['remaining'], '0', 2) === 0);
            if ($done) {
                $source->status = 'converted';
                $source->save();
            }
            $this->audit->record($this->current->id(), $done ? "{$source->type}_converted" : ($source->type === 'purchase_order' ? 'purchase_order_billed' : 'sales_order_delivered'), (string) $source->type, $source->getKey(),
                ['status' => 'accepted'], ['status' => $source->status, 'to' => $to, 'document_id' => $made->getKey(),
                    ...(in_array($source->type, self::ORDERS, true) ? ['delivered' => $quantities] : [])], userId: $userId);

            return $made->refresh()->load('lines');
        });
    }

    /**
     * @param list<string> $from
     * @param array<string, mixed> $set
     */
    private function move(Document $document, string $type, array $from, string $to, string $action, ?string $userId, ?string $note = null, array $set = [], string $actor = 'user'): Document
    {
        return DB::transaction(function () use ($document, $type, $from, $to, $action, $userId, $note, $set, $actor) {
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
            if ($document->type !== $type || ! in_array($document->status, $from, true)) {
                throw new LedgerException(SaveQuote::noun((string) $document->type) . ' ' . ($document->number ?? '(draft)') . " is {$document->status}; it cannot be {$this->verb($to, (string) $document->type)}.");
            }
            $before = $document->only(['number', 'status', 'due_date']);
            if ($document->number === null && in_array($to, ['sent', 'accepted'], true)) {
                if (! $this->numbers->isAutomatic($this->current->id(), $type)) {
                    throw ValidationException::withMessages(['number' => ['Type the ' . strtolower(SaveQuote::noun($type)) . ' number first: numbering is manual.']]);
                }
                $document->number = $this->numbers->take($this->current->get(), $type, new DateTimeImmutable($document->issue_date->format('Y-m-d')));
            }
            $document->forceFill($set);
            $document->status = $to;
            $document->save();
            $this->audit->record($this->current->id(), $action, $type, $document->getKey(), $before,
                $document->only(['number', 'status', 'due_date']), reason: $note, userId: $userId, actorType: $actor);

            return $document->refresh()->load('lines');
        });
    }

    /** Whether a live invoice was made from the order. */
    private function invoiced(Document $order): bool
    {
        return Document::query()->where('source_document_id', $order->getKey())->where('status', '<>', 'void')->exists();
    }

    private function verb(string $to, string $type): string
    {
        return match ($to) {
            'sent' => 'sent', 'accepted' => match ($type) { 'quote' => 'accepted', 'purchase_order' => 'issued', default => 'confirmed' }, 'declined' => 'declined', 'void' => 'cancelled', 'converted' => $type === 'quote' ? 'converted' : 'closed', default => $to,
        };
    }
}
