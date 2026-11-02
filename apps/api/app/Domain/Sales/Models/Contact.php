<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Customers and vendors live in one table; a contact can be both. */
class Contact extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'name', 'is_customer', 'is_vendor', 'tax_id', 'email', 'phone', 'currency', 'payment_terms_days',
        'address', 'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'address' => 'array',
            'custom_fields' => 'array',
        ];
    }
}
