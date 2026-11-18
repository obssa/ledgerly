<?php

/*
| The staff console (SECURITY.md S8, schema 066). Staff accounts are made by an operator with `php artisan
| ledgerly:staff` as the owner role (.github/workflows/staff.yml); the API never makes one.
|
| allowed_ips  the addresses staff may work from (comma-separated, exact addresses). Empty: the console is closed to
|              everyone, which is how it ships.
| session_minutes  how long a support session lasts unless asked for less (at most 120, set by the database)
| token_hours  how long a staff sign-in lasts (at most 8, set by the database)
*/

return [
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('STAFF_ALLOWED_IPS', ''))))),
    'session_minutes' => 60,
    'token_hours' => 8,
    'setup_hours' => 48,
];
