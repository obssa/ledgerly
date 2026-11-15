<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Tenancy\Models\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A location (branch, shop, site), class (department, line of business) or project that transactions are tagged
 * with (database/schema/014_dimensions.sql). Turned off, never deleted.
 */
class Dimension extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const KINDS = ['location', 'class', 'project'];

    /** The journal line column each kind is stored in. */
    public const COLUMNS = ['location' => 'location_id', 'class' => 'class_id', 'project' => 'project_id'];

    protected $fillable = ['company_id', 'kind', 'code', 'name', 'is_active'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
