<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['daily'], 'ignore_exceptions' => false],
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ammp.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 30,
            'tap' => [App\Support\RedactingLogTap::class],
        ],
        'stderr' => ['driver' => 'monolog', 'handler' => Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'tap' => [App\Support\RedactingLogTap::class]],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
