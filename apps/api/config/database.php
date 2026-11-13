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
*/

return [
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
            'search_path' => 'public',
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
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],
];
