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
            $document->type === 'quote' ? 'quote_accepted' : 'sales_order_confirmed', $userId);
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
     * Makes a draft invoice (from a quote or an order) or a draft sales order (from a quote) with the same customer,
     * currency, prices and lines, dated today; the quote or order is accepted first if it was not yet, and is then
     * converted. With manual numbering the new document's number is given here.
     */
    public function convert(Document $source, string $to, string $userId, ?string $number = null): Document
    {
        if (! in_array($to, $source->type === 'quote' ? ['invoice', 'sales_order'] : ['invoice'], true)) {
            throw ValidationException::withMessages(['to' => ['A quote becomes a sales order or an invoice, a sales order an invoice.']]);
        }

        return DB::transaction(function () use ($source, $to, $userId, $number) {
            $source = Document::query()->lockForUpdate()->with('lines')->findOrFail($source->getKey());
            if (in_array($source->status, ['draft', 'sent'], true)) {
                $source = $this->accept($source, $userId)->load('lines');
            }
            if ($source->status !== 'accepted') {
                throw new LedgerException(SaveQuote::noun((string) $source->type) . " {$source->number} is {$source->status}; it cannot be converted.");
            }

            $today = $this->current->today();
            $terms = (int) (DB::table('contacts')->where('id', $source->contact_id)->value('payment_terms_days') ?? 0);
            $data = [
                'contact_id' => $source->contact_id,
                'number' => $number,
                'issue_date' => $today,
                'due_date' => $to === 'invoice'
                    ? (new DateTimeImmutable($today))->modify("+{$terms} days")->format('Y-m-d')
                    : ($source->due_date !== null && $source->due_date->format('Y-m-d') >= $today ? $source->due_date->format('Y-m-d') : null),
                'currency' => $source->currency,
                'fx_rate' => $source->currency === $this->current->get()->base_currency ? null : (string) $source->fx_rate,
                'prices_include_tax' => (bool) $source->prices_include_tax,
                'notes' => $source->notes,
                'warehouse_id' => $source->warehouse_id,
                'salesperson_id' => $source->salesperson_id,
                'lines' => $source->lines->map(fn ($l) => [
                    'description' => (string) $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                    'discount_rate' => (string) $l->discount_rate, 'account_id' => (string) $l->account_id, 'tax_rate_id' => $l->tax_rate_id,
                    'item_id' => $l->item_id, 'project_id' => $l->project_id,
                ])->values()->all(),
            ];

            if ($to === 'invoice') {
                $made = $this->drafts->handle($data, null, $userId);
                $made->forceFill(['source_document_id' => $source->getKey()])->save();   // 056: named once, while a draft
            } else {
                $made = $this->quotes->handle($data, 'sales_order', null, $userId, $source);
            }

            $source->status = 'converted';
            $source->save();
            $this->audit->record($this->current->id(), "{$source->type}_converted", (string) $source->type, $source->getKey(),
                ['status' => 'accepted'], ['status' => 'converted', 'to' => $to, 'document_id' => $made->getKey()], userId: $userId);

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

    private function verb(string $to, string $type): string
    {
        return match ($to) {
            'sent' => 'sent', 'accepted' => $type === 'quote' ? 'accepted' : 'confirmed', 'declined' => 'declined', 'void' => 'cancelled', default => $to,
        };
    }
}
