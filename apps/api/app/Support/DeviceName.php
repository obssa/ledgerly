<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A short name for the device a session was signed in from, such as "Chrome on Windows", for the list of
 * signed-in devices. Only this name is kept, not the browser's full description of itself.
 */
final class DeviceName
{
    private const BROWSERS = [
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'FxiOS/' => 'Firefox', 'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome', 'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'CrOS' => 'ChromeOS',
        'Macintosh' => 'Mac', 'Mac OS X' => 'Mac', 'Linux' => 'Linux',
    ];

    public static function of(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = self::first(self::BROWSERS, $ua);
        $system = self::first(self::SYSTEMS, $ua);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "A browser on {$system}",
            default => 'Unknown device',
        };
    }

    /** @param array<string, string> $names */
    private static function first(array $names, string $ua): ?string
    {
        foreach ($names as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
