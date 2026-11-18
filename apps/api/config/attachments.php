<?php

/*
| Files attached to documents (SECURITY.md S7, schema 063). Kept in private storage outside the web root (on the
| shared host, ~/ledgerly/shared/storage is kept across releases), one folder per company, each file under a random
| name with no extension; never served by the web server, only through the API after a permission check.
*/
return [
    'root' => env('ATTACHMENTS_ROOT', storage_path('app/private/attachments')),
    'max_bytes' => 10 * 1024 * 1024,
    'per_hour' => 60,   // uploads per person
];
