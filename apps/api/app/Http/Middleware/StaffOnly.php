<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ClientAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff console's gate (SECURITY.md S8). First the address: only the allow-listed ones reach any staff route (an
 * empty list closes the console), and anyone else gets a plain 404, as if there were nothing here. Then, for every
 * route but signing in and setting up, a staff token the database itself checks (staff_token_check); the staff member
 * is put on the request. Customers' tokens mean nothing here, and staff tokens nothing anywhere else.
 */
final class StaffOnly
{
    public function handle(Request $request, Closure $next, string $mode = 'signed-in'): Response
    {
        $allowed = (array) config('staff.allowed_ips');
        if ($allowed === [] || ! in_array(ClientAddress::of($request), $allowed, true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        if ($mode === 'open') {
            return $next($request);
        }

        $token = (string) $request->bearerToken();
        $staff = preg_match('/^[0-9a-f]{64}$/', $token) === 1
            ? DB::transaction(fn () => DB::selectOne('SELECT staff_id, name, email FROM staff_token_check(?)', [hash('sha256', $token)]))
            : null;
        if ($staff === null) {
            return response()->json(['message' => 'Sign in as staff.'], 401);
        }
        $request->attributes->set('staff', $staff);
        $request->attributes->set('staff_token_hash', hash('sha256', $token));

        return $next($request);
    }
}
