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
    | Media Disk (book covers, avatars)
    |--------------------------------------------------------------------------
    |
    | Uploaded book covers and user avatars are written to this disk instead
    | of the app-wide default, so the queue/cache/other filesystem usage is
    | unaffected. Local dev keeps using "public" (served from storage/app/public
    | via the storage:link symlink); production sets MEDIA_DISK=s3 pointing at
    | a DigitalOcean Spaces bucket, since App Platform containers are ephemeral
    | and don't persist local disk writes across deploys/restarts.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'public'),

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

        // DigitalOcean Spaces is S3-compatible — reuse the s3 driver, pointed at
        // the Spaces endpoint via AWS_ENDPOINT (e.g. https://sgp1.digitaloceanspaces.com)
        // and AWS_URL for the public base URL (e.g. https://<bucket>.sgp1.digitaloceanspaces.com or a CDN alias).
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Uploads don't pass a visibility per-call, so this is what actually makes
            // book covers/avatars publicly fetchable — without it Flysystem's S3 adapter
            // defaults to private ACL and the URLs the app returns would 403 in the browser.
            'visibility' => 'public',
            'throw' => false,
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
