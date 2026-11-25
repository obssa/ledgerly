<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * SECURITY.md S9: in production the API does not start with debug on, or with the database owner's password in its
 * environment. The owner's password exists only for a deploy's migrations (scripts/deploy/cpanel/release.sh passes it
 * to those commands alone, before the configuration is cached); a web request or a cached configuration holding it
 * would let a bug reach past row-level security.
 */
final class ProductionChecks
{
    public static function assert(Application $app): void
    {
        $problems = self::problems(
            debug: (bool) config('app.debug'),
            ownerPassword: (string) config('database.connections.owner.password'),
            console: $app->runningInConsole(),
            configCached: $app->configurationIsCached(),
            appKey: (string) config('app.key'),
        );
        if ($problems !== []) {
            throw new RuntimeException('Evenbooks will not start in production: ' . implode(' ', $problems));
        }
    }

    /** @return list<string> what is wrong, in words for whoever reads the server's error log */
    public static function problems(bool $debug, string $ownerPassword, bool $console, bool $configCached, string $appKey): array
    {
        $problems = [];
        // Debug and the app key matter to what answers requests. Console commands (composer's package discovery
        // in a build, a deploy's migrations) run before the release's settings are complete, so they are not held to it.
        if ($debug && ! $console) {
            $problems[] = 'APP_DEBUG is on (error pages would show code and settings).';
        }
        if ($ownerPassword !== '' && (! $console || $configCached)) {
            $problems[] = 'DB_OWNER_PASSWORD is set (only a deploy\'s migrations may have it, never the running API or a cached configuration).';
        }
        if ($appKey === '' && ! $console) {
            $problems[] = 'APP_KEY is not set.';
        }

        return $problems;
    }
}
