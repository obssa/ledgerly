<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasUuids;

    protected $fillable = [
        'organisation_id', 'legal_name', 'trading_name', 'country_code', 'base_currency',
        'fiscal_year_start_month', 'calendar', 'accounting_basis', 'industry', 'tin',
        'vat_registered', 'vat_number', 'framework', 'conversion_date', 'books_closed_until', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'books_closed_until' => 'date',
            'conversion_date' => 'date',
            'settings' => 'array',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
