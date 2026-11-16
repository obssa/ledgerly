<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A place stock is kept: a warehouse, a shop, a van. Archived, never deleted. */
class Warehouse extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'is_active'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
