<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated request runs in ONE database transaction:
 *
 *   BEGIN → set the tenant context for this transaction → controller → COMMIT (or ROLLBACK on any error).
 *
 * - The tenant context is transaction-local, so it cannot leak through the connection pool.
 * - A request either happens completely or not at all; jobs dispatched with afterCommit() only leave
 *   once the commit succeeded.
 * - A request that lost a deadlock or serialisation race is safe to run again, so it is retried.
 *
 * ResolveCompany (which runs inside this) adds the company to the context after the membership check.
 */
final class RunInTenantTransaction
{
    private const RETRYABLE = ['40P01', '40001']; // deadlock_detected, serialization_failure

    private const ATTEMPTS = 3;

    public function handle(Request $request, Closure $next): Response
    {
        for ($attempt = 1; ; $attempt++) {
            DB::beginTransaction();

            try {
                DatabaseTenantContext::set($request->user()?->getKey(), null);
                $response = $next($request);
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            // Exceptions thrown by controllers are already rendered to a response at this point.
            $failed = $response->getStatusCode() >= 400 || ($response->exception ?? null) !== null;
            if (! $failed) {
                DB::commit();

                return $response;
            }

            DB::rollBack();

            $error = $response->exception ?? null;
            $retryable = $error instanceof QueryException && in_array((string) $error->getCode(), self::RETRYABLE, true);
            if (! $retryable || $attempt >= self::ATTEMPTS) {
                return $response;
            }

            usleep(random_int(20_000, 80_000) * $attempt);
        }
    }
}
