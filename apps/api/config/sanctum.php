<?php

/*
| API tokens. A token is shown once at sign-in; only its SHA-256 hash is stored.
| A stolen token stops working after `expiration` minutes (default 12 hours); signing out deletes it at once.
*/

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')),
    'guard' => ['web'],
    'expiration' => (int) env('SANCTUM_TOKEN_MINUTES', 720),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'ldg_'), // lets secret scanners recognise a leaked token
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
