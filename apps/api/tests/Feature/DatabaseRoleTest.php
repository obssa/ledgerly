<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DatabaseRoleCheck;
use Tests\RefreshLedgerDatabase;
use Tests\TestCase;

/** The login the API runs as cannot bypass row-level security or change the database (SECURITY.md T34). */
final class DatabaseRoleTest extends TestCase
{
    use RefreshLedgerDatabase;

    public function test_the_application_login_is_restricted(): void
    {
        $this->assertSame([], DatabaseRoleCheck::problems());
        $this->artisan('ledgerly:check-database-roles')->assertSuccessful();
    }

    public function test_the_check_catches_a_login_that_owns_the_tables(): void
    {
        $problems = implode("\n", DatabaseRoleCheck::problems('owner'));

        $this->assertStringContainsString('row-level security does not apply', $problems);
        $this->assertStringContainsString('database owner', $problems);
    }
}
