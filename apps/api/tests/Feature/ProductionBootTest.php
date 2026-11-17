<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ProductionChecks;
use RuntimeException;
use Tests\TestCase;

/** SECURITY.md S9: the check reads the application's real settings (the rules themselves: Unit\ProductionChecksTest). */
final class ProductionBootTest extends TestCase
{
    public function test_debug_on_in_production_stops_the_start(): void
    {
        config(['app.debug' => true, 'database.connections.owner.password' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG is on');
        ProductionChecks::assert($this->app);
    }

    public function test_a_deploys_migration_may_start_with_the_owner_password(): void
    {
        config(['app.debug' => false, 'database.connections.owner.password' => 'not-a-real-password']);
        $this->assertTrue($this->app->runningInConsole(), 'tests run from the console, as a deploy migrates');
        ProductionChecks::assert($this->app);   // no exception: the console, no cached configuration
        $this->addToAssertionCount(1);
    }
}
