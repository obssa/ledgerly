<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Support\CurrentCompany;
use App\Domain\Tenancy\Support\DatabaseTenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the company for this request from the X-Company-Id header, checks the user is an active member,
 * and adds the company to the transaction's tenant context so row-level security applies to every query
 * that follows. Runs inside RunInTenantTransaction.
 *
 * Refuses owners and admins who have not set up an authenticator app (config/security.php).
 *
 * Also applies the two read-only states: a suspended or cancelled subscription and an archived company
 * can be read and exported but not changed.
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

        // Only the user id is in the context so far, so RLS lets us see the user's own memberships.
        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'You do not have access to this company.'], 403);
        }

        // Owners and admins can change everything, so they work only with a second factor (SECURITY.md S2).
        if (in_array($membership->role, (array) config('security.mfa.required_roles'), true) && ! $user->mfa_enabled) {
            return response()->json([
                'message' => 'Set up an authenticator app to work in this company: it is required for owners and admins.',
                'type' => 'MfaEnrolmentRequired',
            ], 403);
        }

        DatabaseTenantContext::set($user->getKey(), $companyId);
        $company = Company::query()->findOrFail($companyId);
        $this->current->set($company, $membership);

        try {
            if (! $request->isMethodSafe()) {
                $subscription = DB::table('organisations')->where('id', $company->organisation_id)->value('status');

                if (in_array($subscription, ['suspended', 'cancelled'], true)) {
                    return response()->json([
                        'message' => "The subscription is {$subscription}. The books are read-only; you can still view and export them.",
                        'type' => 'SubscriptionReadOnly',
                    ], 402);
                }
                if ($company->status === 'archived') {
                    return response()->json(['message' => 'This company is archived and read-only.', 'type' => 'CompanyArchived'], 423);
                }
            }

            return $next($request);
        } finally {
            $this->current->forget();
        }
    }
}
