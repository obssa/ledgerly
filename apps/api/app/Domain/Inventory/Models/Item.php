<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What the company sells or buys: stock items (with a bin card), non-stock items and services. Archived, never deleted. */
class Item extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['sku', 'name', 'type', 'unit', 'sale_price', 'purchase_price', 'income_account_id', 'expense_account_id',
        'default_tax_code', 'authority_item_code', 'reorder_point', 'is_active'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function isStock(): bool
    {
        return $this->type === 'inventory';
    }
}
