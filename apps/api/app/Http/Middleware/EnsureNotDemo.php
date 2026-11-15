<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\DemoAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every visitor shares the demo accounts, so none of them may change what would lock the others out or follow
 * the account elsewhere: password, authenticator, email, sessions on other devices, new companies.
 */
final class EnsureNotDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoAccounts::isDemo($request->user())) {
            return response()->json(['message' => 'Not available in the demo. Create your own account to use this.', 'type' => 'DemoAccount'], 403);
        }

        return $next($request);
    }
}
