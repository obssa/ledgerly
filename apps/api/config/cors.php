<?php

/*
| SECURITY.md S5. Browsers may call the API only from Evenbooks' own web app (FRONTEND_URL); no wildcard and no
| credentials, as the API uses bearer tokens and no cookies. The web app calls the API from its own server, where
| CORS does not apply, so this only shuts the door on pages of other sites.
*/

$frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_origins' => $frontend === '' ? [] : [$frontend],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Company-Id', 'Idempotency-Key'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => false,
];
