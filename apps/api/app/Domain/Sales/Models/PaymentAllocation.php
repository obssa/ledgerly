<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which invoice a payment settled, and how much. Inserting one lowers the invoice's amount due (006). */
class PaymentAllocation extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['company_id'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
