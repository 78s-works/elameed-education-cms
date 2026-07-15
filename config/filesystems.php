<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // External media store (source video + encrypted HLS). S3-compatible, so
        // it works with any provider — self-hosted MinIO, Bunny, Backblaze B2,
        // Wasabi, DigitalOcean Spaces, Cloudflare R2, or AWS S3 — with no code
        // lock-in. The bucket MUST be PRIVATE: all delivery is via short-lived
        // presigned URLs minted per playback, never public objects.
        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_S3_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('MEDIA_S3_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('MEDIA_S3_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('MEDIA_S3_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('MEDIA_S3_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => (bool) env('MEDIA_S3_USE_PATH_STYLE', env('AWS_USE_PATH_STYLE_ENDPOINT', true)),
            'visibility' => 'private',
            'throw' => true,   // fail loudly on media I/O — a silent miss must not look "ready"
            'report' => false,
        ],

        // Local-filesystem media store. Root is configurable via MEDIA_LOCAL_ROOT
        // so it can live OUTSIDE the app (e.g. D:/edu-system on a dedicated disk),
        // surviving redeploys and keeping large media off the project tree. Kept
        // private — nothing is reachable except through the token-gated delivery
        // endpoints. Delivery is proxied by the app (a local folder can't mint
        // presigned URLs), so keep MEDIA_PROVIDER=local with this disk.
        'media_local' => [
            'driver' => 'local',
            'root' => env('MEDIA_LOCAL_ROOT', storage_path('app/media')),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
