<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Customers and suppliers live in one table; a contact can be both (is_customer, is_vendor). */
class Contact extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'name', 'is_customer', 'is_vendor', 'tax_id', 'email', 'phone', 'website', 'currency', 'payment_terms_days', 'payment_terms_id',
        'address', 'custom_fields', 'credit_limit', 'tax_exempt', 'tax_exempt_reason', 'delivery_address', 'notes', 'is_active', 'price_list_id',
        'payout', 'default_expense_account_id', 'withholding_exempt', 'withholding_exempt_reason',
    ];

    protected $hidden = ['company_id', 'custom_fields'];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'address' => 'array',
            'delivery_address' => 'array',
            'custom_fields' => 'array',
            'tax_exempt' => 'boolean',
            'is_active' => 'boolean',
            'payout' => 'array',
            'withholding_exempt' => 'boolean',
        ];
    }
}
