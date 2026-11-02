<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the company for this request from the X-Company-Id header, checks the user belongs to it,
 * and sets the database tenant context so row-level security applies to every query that follows.
 */
final class ResolveCompany
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = (string) $request->header('X-Company-Id');

        if (! $user || ! Str::isUuid($companyId)) {
            return response()->json(['message' => 'Send the X-Company-Id header with a company you belong to.'], 400);
        }

        // Only the user id is set at this point, so RLS lets us see the user's own memberships.
        DatabaseTenantContext::set($user->getKey(), null);

        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $companyId)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'You do not have access to this company.'], 403);
        }

        DatabaseTenantContext::set($user->getKey(), $companyId);
        $this->current->set(Company::query()->findOrFail($companyId), $membership);

        try {
            return $next($request);
        } finally {
            DatabaseTenantContext::clear();
            $this->current->forget();
        }
    }
}
