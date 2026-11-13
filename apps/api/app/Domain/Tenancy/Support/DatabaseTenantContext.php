<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pushes the tenant into PostgreSQL settings read by the row-level-security policies
 * (database/schema/007_rls.sql).
 *
 * The settings are LOCAL TO THE CURRENT TRANSACTION (set_config(..., true), i.e. SET LOCAL): they vanish
 * at COMMIT or ROLLBACK. Nothing has to be cleared, and a pooled connection (PgBouncer in transaction
 * mode, Octane workers) can never carry one company's context into the next request. For the same
 * reason set() refuses to run outside a transaction: the context would otherwise be lost immediately.
 */
final class DatabaseTenantContext
{
    public static function set(?string $userId, ?string $companyId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The tenant context must be set inside a database transaction.');
        }

        DB::select("select set_config('app.user_id', ?, true), set_config('app.company_id', ?, true)", [
            $userId ?? '',
            $companyId ?? '',
        ]);
    }

    /**
     * Runs $work in one transaction with the tenant set. Queued jobs, console commands and anything else
     * outside an HTTP request use this; HTTP requests get the same from the RunInTenantTransaction middleware.
     *
     * @template T
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(?string $userId, ?string $companyId, Closure $work): mixed
    {
        return DB::transaction(function () use ($userId, $companyId, $work) {
            self::set($userId, $companyId);

            return $work();
        });
    }
}
