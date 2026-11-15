<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A payment received or made (database/schema/016_payments.sql): recorded once, only ever reversed. */
class Payment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
