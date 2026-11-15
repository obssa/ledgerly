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
        'language', 'notes', 'location_id', 'custom_fields', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'prices_include_tax' => 'boolean',
            'custom_fields' => 'array',
            'lock_version' => 'integer',
        ];
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
