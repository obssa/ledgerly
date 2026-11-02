<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application-level tenant scoping. PostgreSQL row-level security is the second, independent wall:
 * a bug here still cannot leak another company's rows.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $current = app(CurrentCompany::class);
            if ($current->has()) {
                $query->where($query->getModel()->getTable() . '.company_id', $current->id());
            }
        });

        static::creating(function ($model) {
            $current = app(CurrentCompany::class);
            if (! $model->company_id && $current->has()) {
                $model->company_id = $current->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
