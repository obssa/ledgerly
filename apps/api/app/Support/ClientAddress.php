<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address of the person behind a request, for sign-in limits and lockouts (SECURITY.md S16).
 *
 * The web app signs people in from its own server, so to the API every visitor would have that server's
 * address: one person's wrong passwords would lock everyone out. The web server therefore passes the
 * visitor's address in X-Ledgerly-Client-Address, and proves it is the web server with X-Ledgerly-Web-Key
 * (WEB_SERVER_KEY, the same secret on both sides). Without the right key the header is ignored, so a caller
 * cannot pick the address it is counted under.
 */
final class ClientAddress
{
    public static function of(Request $request): string
    {
        $key = (string) config('security.web_server_key');
        $given = (string) $request->header('X-Ledgerly-Web-Key');
        $forwarded = trim((string) $request->header('X-Ledgerly-Client-Address'));

        if ($key !== '' && $given !== '' && hash_equals($key, $given) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return (string) $request->ip();
    }
}
