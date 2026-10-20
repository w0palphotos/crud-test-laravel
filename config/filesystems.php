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

            // Root-relative, deliberately not built from APP_URL.
            //
            // The browser resolves this against the host actually serving the page,
            // so the same stored value is correct on localhost, on the deployment,
            // and on a domain bought later — none of which requires a matching
            // APP_URL and none of which can drift out of step with it.
            //
            // Built from APP_URL it was wrong the moment the two disagreed, and the
            // failure was silent until a browser met it: images pointed at whatever
            // host was configured somewhere else. Note that APP_URL still has to be
            // correct — route() uses it for every form's submit endpoint — so this
            // removes one way to get it wrong, not the obligation to set it.
            'url' => '/storage',
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

            // No visibility here on purpose. A bucket with owner-enforced ownership
            // — the default everywhere since 2023 — accepts neither "private" nor
            // "public-read" as a canned ACL, and the file adapter sends one of
            // those two whatever is configured here. The upload sends
            // bucket-owner-full-control instead, which is the one value that is
            // legal there and correct on older buckets; see StoreUploadedImage.
            //
            // And it grants nothing: it says who owns the object, not who may
            // read it. Whether a file can be read without a login is the bucket's
            // own setting, not this.

            // A write raises, so the reason reaches the person who can act on it.
            //
            // The storage client's error is the only thing that knows whether the
            // bucket was not found, the key was rejected or the region did not
            // match. It used to be discarded three times over: the adapter caught
            // it, 'throw' said not to raise it, and 'report' said not to log it
            // either. Nothing anywhere said why an upload had failed.
            //
            // Reads are unaffected. A key that is not there still reads as absent,
            // because the client's own existence check answers false for a 404 and
            // raises for anything else — so the "file not found" validation still
            // works, and a broken storage is a 500 rather than a misleading
            // message about a file that was never missing.
            'throw' => true,

            // And the reason is reported, so the full error — the code, the
            // message, the request id — reaches the log.
            'report' => true,
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
