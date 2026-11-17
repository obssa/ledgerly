<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address of the person behind a request, for sign-in limits and lockouts (SECURITY.md S16), and the
 * browser they use, for the list of signed-in devices.
 *
 * The web app signs people in from its own server, so to the API every visitor would have that server's
 * address: one person's wrong passwords would lock everyone out. The web server therefore passes the
 * visitor's address in X-Ledgerly-Client-Address (and their browser in X-Ledgerly-Client-Agent), and proves
 * it is the web server with X-Ledgerly-Web-Key (WEB_SERVER_KEY, the same secret on both sides). Without the
 * right key the headers are ignored, so a caller cannot pick the address it is counted under.
 */
final class ClientAddress
{
    public static function of(Request $request): string
    {
        $forwarded = trim((string) $request->header('X-Ledgerly-Client-Address'));

        if (self::fromWebServer($request) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return (string) $request->ip();
    }

    /** The visitor's browser, as it describes itself (at most 500 characters). */
    public static function agent(Request $request): string
    {
        $forwarded = self::fromWebServer($request) ? trim((string) $request->header('X-Ledgerly-Client-Agent')) : '';

        return mb_substr($forwarded !== '' ? $forwarded : (string) $request->userAgent(), 0, 500);
    }

    private static function fromWebServer(Request $request): bool
    {
        $key = (string) config('security.web_server_key');
        $given = (string) $request->header('X-Ledgerly-Web-Key');

        return $key !== '' && $given !== '' && hash_equals($key, $given);
    }
}
