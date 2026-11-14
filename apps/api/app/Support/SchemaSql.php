<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads a file of the core schema (database/schema/00*.sql) and fills its two placeholders from
 * configuration (config/database.php 'ledgerly'), never from request input:
 *
 *   {{app_role}}  the role the application's policies and grants are for
 *   {{schema}}    the schema the tables live in
 *
 * Both must be plain lower-case identifiers, so a mistyped setting fails the migration instead of
 * reaching the SQL.
 */
final class SchemaSql
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/';

    public static function load(string $file): string
    {
        $sql = file_get_contents(database_path("schema/{$file}"));
        if ($sql === false) {
            throw new InvalidArgumentException("Schema file {$file} not found.");
        }

        return strtr($sql, ['{{app_role}}' => self::appRole(), '{{schema}}' => self::schema()]);
    }

    public static function appRole(): string
    {
        return self::identifier('app_role', (string) config('database.ledgerly.app_role', 'ledgerly_app'));
    }

    public static function schema(): string
    {
        return self::identifier('schema', (string) config('database.ledgerly.schema', 'public'));
    }

    private static function identifier(string $setting, string $value): string
    {
        if (! preg_match(self::IDENTIFIER, $value)) {
            throw new InvalidArgumentException("database.ledgerly.{$setting} must be a lower-case identifier, got '{$value}'.");
        }

        return $value;
    }
}
