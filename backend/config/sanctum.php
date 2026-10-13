<?php

return [
    // Hosts allowed to use cookie (SPA) authentication. The admin UI is served from the same origin, so the server's own
    // host is always allowed (currentRequestHost) — the console then works at http://<PC IP>:8000/admin too, not only localhost.
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1') . \Laravel\Sanctum\Sanctum::currentRequestHost()),
    'guard' => ['web'],
    'expiration' => null,
    'token_prefix' => '',
    'middleware' => [
        // Disabled: it resolves the session user before the company is bound (tenant scope would throw).
        // Session expiry/rotation is enforced by EnsureMfaVerified (idle + absolute limits) instead.
        'authenticate_session' => null,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
