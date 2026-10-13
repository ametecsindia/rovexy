<?php

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => [
        // Admin desktop/web: cookie session (Sanctum stateful SPA).
        'web' => ['driver' => 'session', 'provider' => 'users'],
        // Ametecs staff (Super Admin console, /super). Same cookie session, separate identity.
        'platform' => ['driver' => 'session', 'provider' => 'platform_admins'],
    ],
    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
        'platform_admins' => ['driver' => 'eloquent', 'model' => App\Models\Platform\PlatformAdmin::class],
    ],
    'passwords' => [
        'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 30, 'throttle' => 60],
    ],
    'password_timeout' => 900,
];
