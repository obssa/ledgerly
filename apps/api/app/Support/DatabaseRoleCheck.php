<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Checks, from inside the application's own connection, that the login it runs as is as restricted as
 * row-level security needs it to be (SECURITY.md §2, T34). Run by the deploy after migrating
 * (`php artisan ledgerly:check-database-roles`) and by DatabaseRoleTest.
 *
 * It exists because hosting panels grant more than they say: cPanel's "grant all privileges" makes the login
 * a member of the database-owner role, which can drop the database, and a login that owns (or is a member of
 * the owner of) the tables is not subject to their row-level security at all.
 */
final class DatabaseRoleCheck
{
    /** @return list<string> what is wrong; empty when the login is properly restricted */
    public static function problems(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $schema = SchemaSql::schema();
        $problems = [];

        $me = $db->selectOne('SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname = current_user');
        foreach (['rolsuper' => 'is a superuser', 'rolbypassrls' => 'bypasses row-level security', 'rolcreaterole' => 'can create roles', 'rolcreatedb' => 'can create databases'] as $flag => $what) {
            if ($me->{$flag}) {
                $problems[] = "{$me->rolname} {$what}.";
            }
        }

        $database = $db->selectOne(
            "SELECT d.datdba::regrole::text AS owner, pg_has_role(current_user, d.datdba, 'MEMBER') AS member
               FROM pg_database d WHERE d.datname = current_database()",
        );
        if ($database->member) {
            $problems[] = "{$me->rolname} is (a member of) the database owner {$database->owner}, so it can drop or alter the database. On cPanel: uapi Postgresql revoke_all_privileges user={$me->rolname} database=<database>.";
        }

        $tables = $db->selectOne(
            "SELECT count(*) AS total, count(*) FILTER (WHERE pg_has_role(current_user, c.relowner, 'MEMBER')) AS owned
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ? AND c.relkind IN ('r', 'p')",
            [$schema],
        );
        if ((int) $tables->total === 0) {
            $problems[] = "No tables in schema {$schema}: check DB_SCHEMA, or migrate first.";
        }
        if ((int) $tables->owned > 0) {
            $problems[] = "{$me->rolname} owns (or is a member of the owner of) {$tables->owned} tables in {$schema}: row-level security does not apply to it.";
        }

        if ($db->selectOne("SELECT has_schema_privilege(current_user, ?, 'CREATE') AS can", [$schema])->can) {
            $problems[] = "{$me->rolname} may create objects in schema {$schema}.";
        }

        return $problems;
    }
}
