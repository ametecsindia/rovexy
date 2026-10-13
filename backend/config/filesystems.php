<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => true],
        // Private recordings store. Keys are always "companies/{company_id}/…". Never public.
        'recordings' => env('RECORDINGS_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('RECORDINGS_S3_KEY'),
                'secret' => env('RECORDINGS_S3_SECRET'),
                'region' => env('RECORDINGS_S3_REGION', 'ap-south-1'),
                'bucket' => env('RECORDINGS_S3_BUCKET'),
                'endpoint' => env('RECORDINGS_S3_ENDPOINT'),         // S3-compatible (MinIO, GCS interop, Wasabi…)
                'use_path_style_endpoint' => (bool) env('RECORDINGS_S3_PATH_STYLE', false),
                'visibility' => 'private',
                'throw' => true,
            ]
            : ['driver' => 'local', 'root' => env('RECORDINGS_LOCAL_ROOT', storage_path('app/recordings')), 'visibility' => 'private', 'throw' => true],
        'company_files' => ['driver' => 'local', 'root' => storage_path('app/company'), 'visibility' => 'private', 'throw' => true],
    ],
];
