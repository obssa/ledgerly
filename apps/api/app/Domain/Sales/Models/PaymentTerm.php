<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A term from QuickBooks' Terms list (109): days, or a day of the month. Turned off, never deleted. */
class PaymentTerm extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['name', 'kind', 'days', 'day_of_month', 'next_month_within', 'is_active'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'day_of_month' => 'integer',
            'next_month_within' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
