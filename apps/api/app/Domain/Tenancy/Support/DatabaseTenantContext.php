<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pushes the tenant into PostgreSQL session settings read by the row-level-security policies
 * (database/schema/005_rls.sql). Always pair set() with clear(): connections are reused.
 */
final class DatabaseTenantContext
{
    public static function set(?string $userId, ?string $companyId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select("select set_config('app.user_id', ?, false), set_config('app.company_id', ?, false)", [
            $userId ?? '',
            $companyId ?? '',
        ]);
    }

    public static function clear(): void
    {
        self::set(null, null);
    }
}
