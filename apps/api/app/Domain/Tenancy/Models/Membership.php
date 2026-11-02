<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'user_id', 'role', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** Role permissions from config/permissions.php plus any extra grants (for the 'custom' role). */
    public function can(string $permission): bool
    {
        $granted = array_merge(config("permissions.roles.{$this->role}", []), $this->permissions ?? []);

        foreach ($granted as $grant) {
            if ($grant === '*' || $grant === $permission
                || (str_ends_with($grant, '.*') && str_starts_with($permission, substr($grant, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
