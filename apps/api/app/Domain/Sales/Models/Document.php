<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Compliance\Models\EInvoiceSubmission;
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

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'approved'], true);
    }
}
