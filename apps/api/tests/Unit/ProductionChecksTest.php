<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductionChecks;
use PHPUnit\Framework\TestCase;

/** SECURITY.md S9: the API refuses to start in production with debug on or the owner's password in reach. */
final class ProductionChecksTest extends TestCase
{
    public function test_a_sound_production_setup_starts(): void
    {
        $this->assertSame([], ProductionChecks::problems(debug: false, ownerPassword: '', console: false, configCached: true, appKey: 'base64:k'));
    }

    public function test_debug_on_or_no_app_key_stops_it(): void
    {
        $this->assertCount(1, ProductionChecks::problems(debug: true, ownerPassword: '', console: false, configCached: true, appKey: 'base64:k'));
        $this->assertCount(1, ProductionChecks::problems(debug: false, ownerPassword: '', console: true, configCached: false, appKey: ''));
    }

    public function test_the_owner_password_is_allowed_only_to_a_deploys_migrations(): void
    {
        // The deploy migrates from the console, before the configuration is cached.
        $this->assertSame([], ProductionChecks::problems(debug: false, ownerPassword: 'secret', console: true, configCached: false, appKey: 'base64:k'));
        // Never while serving requests, and never written into a cached configuration.
        $this->assertCount(1, ProductionChecks::problems(debug: false, ownerPassword: 'secret', console: false, configCached: false, appKey: 'base64:k'));
        $this->assertCount(1, ProductionChecks::problems(debug: false, ownerPassword: 'secret', console: true, configCached: true, appKey: 'base64:k'));
    }
}
