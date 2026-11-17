<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'document_id', 'line_no', 'item_id', 'description', 'quantity', 'unit_price', 'discount_rate',
        'tax_rate_id', 'account_id', 'line_subtotal', 'line_tax', 'project_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
