<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Compliance\Models\EInvoiceSubmission;
use App\Domain\Ledger\Exceptions\StaleDocument;
use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Quotes, invoices, credit notes, bills ... one table, told apart by `type`. */
class Document extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'type', 'number', 'contact_id', 'issue_date', 'due_date', 'currency', 'fx_rate', 'prices_include_tax',
        'language', 'notes', 'location_id', 'custom_fields', 'created_by', 'original_document_id', 'reference',
        'paid_from_account_id', 'payment_method', 'warehouse_id', 'salesperson_id', 'payment_terms_id', 'out_of_scope', 'class_id',
        'billing_address', 'shipping_address', 'email_to', 'email_cc', 'email_bcc', 'ship_via', 'ship_date', 'tracking_number', 'message_on_statement',
    ];

    /** 110: read back as QuickBooks' Amounts are. */
    protected $appends = ['tax_mode'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',   // sent as 2026-11-16, not a timestamp
            'due_date' => 'date:Y-m-d',
            'ship_date' => 'date:Y-m-d',
            'prices_include_tax' => 'boolean',
            'out_of_scope' => 'boolean',
            'custom_fields' => 'array',
            'lock_version' => 'integer',
            'opening' => 'boolean',
            'restock' => 'boolean',
        ];
    }

    /** Exclusive of tax, inclusive of tax, or out of scope of tax (110). */
    public function getTaxModeAttribute(): ?string
    {
        $raw = $this->getAttributes();
        if (! array_key_exists('prices_include_tax', $raw)) {
            return null;   // read with a few columns only (a list, a link)
        }

        return ! empty($raw['out_of_scope']) ? 'out_of_scope' : ($this->prices_include_tax ? 'inclusive' : 'exclusive');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('line_no');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function einvoiceSubmissions(): HasMany
    {
        return $this->hasMany(EInvoiceSubmission::class);
    }

    /** Not yet in the ledger: saved freely. Once posted, changes go through EditPostedInvoice. */
    /** The number, or "the draft" while an automatically numbered draft has none yet. */
    public function label(): string
    {
        return $this->number ?? 'the draft';
    }

    /** The bank, cash or wallet account an expense was paid from. */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Ledger\Models\Account::class, 'paid_from_account_id');
    }

    /** The invoice a credit note corrects. */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'awaiting_approval'], true);
    }

    /** In the ledger (posted, part paid or paid). */
    public function isPosted(): bool
    {
        return in_array($this->status, ['posted', 'part_paid', 'paid'], true);
    }

    /**
     * Optimistic locking. The client sends the lock_version it read; if someone saved in between, the
     * database has moved on (it bumps the version on every save) and this request is refused.
     */
    public function assertVersion(?int $expected): void
    {
        if ($expected !== null && (int) $this->lock_version !== $expected) {
            throw StaleDocument::for((string) $this->number);
        }
    }
}
