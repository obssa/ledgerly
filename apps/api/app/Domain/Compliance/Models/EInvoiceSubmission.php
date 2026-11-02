<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Models;

use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EInvoiceSubmission extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'einvoice_submissions';

    protected $fillable = ['document_id', 'authority', 'status'];

    protected $hidden = ['request_payload', 'signature'];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'submitted_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
