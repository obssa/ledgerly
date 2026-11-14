<?php

/*
| Sign-in security (SECURITY.md S2, S16).
|
| mfa.required_roles  members with these roles cannot work in a company until they have set up an
|                     authenticator app. Everyone else may set one up, and is then asked for a code too.
| mfa.issuer          the name the authenticator app shows next to the account.
| web_server_key      shared with the web app (its LEDGERLY_WEB_KEY). A request carrying it may say which
|                     visitor it acts for, so sign-in limits count people, not the web server
|                     (App\Support\ClientAddress). Empty: the header is ignored.
*/

return [
    'web_server_key' => env('WEB_SERVER_KEY', ''),

    // Where the links in emails lead: the web app (SECURITY.md S3).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'email' => ['verify_minutes' => 24 * 60],
    'password' => ['reset_minutes' => 60],

    'mfa' => [
        'required_roles' => ['owner', 'admin'],
        'issuer' => env('APP_NAME', 'Ledgerly'),
        'challenge_minutes' => 5,
        'max_attempts' => 5,
        'lock_seconds' => 900,
    ],
];
