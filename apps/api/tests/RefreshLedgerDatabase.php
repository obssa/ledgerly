<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every feature test a freshly migrated database, built the way production is built:
 *
 *   - the schema is created by the OWNER connection (ledgerly_owner, bypasses row-level security);
 *   - every HTTP request in the test runs on the default connection as ledgerly_web, where row-level
 *     security, column grants and the access triggers all apply (SECURITY.md task S1).
 *
 * Fixtures and checks on stored data go through owner(): a test must never weaken what the application
 * role is allowed to do just to arrange its data.
 *
 * Not RefreshDatabase: the balance check is a DEFERRED constraint trigger and fires only at COMMIT, which a
 * per-test rollback never reaches. Not DatabaseMigrations: its teardown rolls back, and the ledger schema
 * refuses to roll back by design. So the public schema is dropped and rebuilt instead.
 */
trait RefreshLedgerDatabase
{
    protected function setUpRefreshLedgerDatabase(): void
    {
        $app = config('database.connections.pgsql.username');
        $owner = config('database.connections.owner.username');
        if (! $owner || $app === $owner) {
            $this->fail('Feature tests need DB_USERNAME (application role) and DB_OWNER_USERNAME (owner) to be different logins.');
        }
        // This drops the whole schema. Never on a database that is not obviously a test database.
        foreach (['pgsql', 'owner'] as $connection) {
            $database = (string) config("database.connections.{$connection}.database");
            if (! str_ends_with($database, '_test')) {
                $this->fail("Refusing to rebuild '{$database}' ({$connection} connection): feature tests only run on a database whose name ends in _test.");
            }
        }

        DB::purge('pgsql');
        $this->owner()->unprepared(
            'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public; GRANT USAGE ON SCHEMA public TO ledgerly_app;'
        );
        $this->artisan('migrate', ['--database' => 'owner', '--force' => true])->assertSuccessful();
        $this->owner()->disconnect();
    }

    /** The owner connection, for arranging fixtures and inspecting stored rows. Bypasses row-level security. */
    protected function owner(): Connection
    {
        return DB::connection('owner');
    }
}
