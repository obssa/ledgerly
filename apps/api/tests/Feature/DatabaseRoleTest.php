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
        config(['database.ledgerly.allow_owner_role_membership' => false]);
        $problems = implode("\n", DatabaseRoleCheck::problems('owner'));

        $this->assertStringContainsString('row-level security does not apply', $problems);
        $this->assertStringContainsString('database owner', $problems);
    }

    public function test_allowing_owner_role_membership_downgrades_only_that_finding(): void
    {
        config(['database.ledgerly.allow_owner_role_membership' => true]);
        ['problems' => $problems, 'warnings' => $warnings] = DatabaseRoleCheck::report('owner');

        $this->assertStringNotContainsString('database owner', implode("\n", $problems));
        $this->assertStringContainsString('database owner', implode("\n", $warnings));
        // Table ownership is never allowed: that is what row-level security does not apply to.
        $this->assertStringContainsString('row-level security does not apply', implode("\n", $problems));
    }
}
