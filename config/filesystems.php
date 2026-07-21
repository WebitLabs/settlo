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

        // Private disk for financial documents (receipts). Never served
        // directly — access only through an authenticated, tenant-checked
        // download route. No public URL. Local by default; set
        // RECEIPTS_DISK_DRIVER=s3 to store on DigitalOcean Spaces.
        'receipts' => match (env('RECEIPTS_DISK_DRIVER', 'local')) {
            's3' => [
                'driver' => 's3',
                'key' => env('DO_SPACES_KEY'),
                'secret' => env('DO_SPACES_SECRET'),
                'region' => env('DO_SPACES_REGION'),
                'bucket' => env('DO_SPACES_BUCKET'),
                'endpoint' => env('DO_SPACES_ENDPOINT'),
                'root' => 'receipts',
                'visibility' => 'private',
                'use_path_style_endpoint' => false,
                'throw' => false,
                'report' => false,
            ],
            default => [
                'driver' => 'local',
                'root' => storage_path('app/receipts'),
                'serve' => false,
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ],
        },

        // Public assets (logos). Local + storage:link by default; set
        // PUBLIC_DISK_DRIVER=s3 to store on DigitalOcean Spaces, with URLs
        // served through the Spaces CDN (DO_SPACES_CDN_URL).
        'public' => match (env('PUBLIC_DISK_DRIVER', 'local')) {
            's3' => [
                'driver' => 's3',
                'key' => env('DO_SPACES_KEY'),
                'secret' => env('DO_SPACES_SECRET'),
                'region' => env('DO_SPACES_REGION'),
                'bucket' => env('DO_SPACES_BUCKET'),
                'endpoint' => env('DO_SPACES_ENDPOINT'),
                'root' => 'public',
                'url' => env('DO_SPACES_CDN_URL'),
                'visibility' => 'public',
                'use_path_style_endpoint' => false,
                'throw' => false,
                'report' => false,
            ],
            default => [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],
        },

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
