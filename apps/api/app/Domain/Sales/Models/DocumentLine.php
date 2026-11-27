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
        'tax_rate_id', 'account_id', 'line_subtotal', 'line_tax', 'project_id', 'source_line_id',
        'unit_id',   // 072: the database fills unit_name and unit_factor from it
        'batch_number', 'batch_expires_on',   // 074: the batch a bill or credit-note line brings in
        'service_date', 'class_id', 'location_id',   // 111
    ];

    protected function casts(): array
    {
        return ['batch_expires_on' => 'date', 'service_date' => 'date:Y-m-d'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
