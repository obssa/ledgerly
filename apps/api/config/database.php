<?php

/*
| Two PostgreSQL logins, on purpose (SECURITY.md §2, §4):
|
|   pgsql  ledgerly_web    what the API runs as. A member of ledgerly_app, not the owner: row-level security
|                          applies to every query. This is the default connection.
|   owner  ledgerly_owner  owns the tables and bypasses row-level security. Used ONLY to run migrations
|                          (`php artisan migrate --database=owner`) and by tests to arrange fixtures.
|                          In production its credentials exist only in the deploy job, never in the
|                          API's environment, so this connection cannot be opened by a running request.
|
| Laravel merges this file's `connections` with its own defaults (sqlite, mysql, pgsql, ...).
|
| `ledgerly` fills the schema's placeholders (App\Support\SchemaSql). The defaults suit a server where
| roles can be created. On shared hosting (cPanel) the app role is the application login itself and the
| tables live in their own schema, because anyone may write to `public` there:
|   DB_APP_ROLE=orcamiib_lweb  DB_SCHEMA=ledgerly
| cPanel also lets a login connect only as a member of the database-owner role, so there
|   DB_ALLOW_OWNER_ROLE_MEMBERSHIP=true   (App\Support\DatabaseRoleCheck; SECURITY.md finding 23)
*/

return [
    'ledgerly' => [
        'app_role' => env('DB_APP_ROLE', 'ledgerly_app'),
        'schema' => env('DB_SCHEMA', 'public'),
        'allow_owner_role_membership' => (bool) env('DB_ALLOW_OWNER_ROLE_MEMBERSHIP', false),
    ],

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ledgerly'),
            'username' => env('DB_USERNAME', 'ledgerly_web'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // Query errors must not copy amounts, emails or tokens into logs (SECURITY.md S10).
            'mask_bindings_in_exception_messages' => env('DB_MASK_BINDINGS', true),
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // The same login on its own session, for the database cache store (DB_CACHE_CONNECTION=cache where
        // there is no Redis). Rate-limit counters live in the cache: on the request's own connection a
        // failed, rolled-back request would erase its own failed attempt.
        'cache' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ledgerly'),
            'username' => env('DB_USERNAME', 'ledgerly_web'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'mask_bindings_in_exception_messages' => env('DB_MASK_BINDINGS', true),
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'owner' => [
            'driver' => 'pgsql',
            'host' => env('DB_OWNER_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_OWNER_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'ledgerly'),
            'username' => env('DB_OWNER_USERNAME'),
            'password' => env('DB_OWNER_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'mask_bindings_in_exception_messages' => env('DB_MASK_BINDINGS', true),
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
];
