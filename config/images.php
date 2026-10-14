<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image Disk
    |--------------------------------------------------------------------------
    |
    | Which filesystem disk uploaded images land on.
    |
    | Two disks exist and this picks between them rather than configuring a third:
    |
    |   public  local disk, served from /storage. The default, and the only one
    |           that works with no credentials at all, so a fresh checkout and the
    |           SQLite test suite need nothing set up.
    |   s3      Supabase Storage over the S3 protocol. Production, because a
    |           container's local disk is ephemeral and an image uploaded into it
    |           disappears when the instance is recycled.
    |
    | Run `php artisan storage:link` for the local disk to be reachable over HTTP.
    | It is a development convenience only; Supabase serves its own URLs.
    |
    */

    'disk' => env('IMAGES_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload Rules
    |--------------------------------------------------------------------------
    |
    | The limits the upload endpoint enforces. They live here rather than only in
    | the form request so the endpoint, the docs and anything that generates a
    | client-side size hint all read the same numbers.
    |
    | SVG is deliberately not on the allow list. It is an image by MIME type and
    | it is also a document that can carry script, so a whitelist of raster
    | formats is the difference between "the browser renders this" and "the
    | browser runs what the uploader sent".
    |
    */

    'max_kilobytes' => (int) env('IMAGES_MAX_KILOBYTES', 2048),

    'mimetypes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Path
    |--------------------------------------------------------------------------
    |
    | Every upload goes into this one directory, under a generated name.
    |
    | Not a client-supplied folder: a path assembled from input is how an upload
    | escapes its directory and lands somewhere else. One directory means there is
    | nothing for a caller to aim at, and a UUID filename cannot collide or be
    | guessed from a product name.
    |
    */

    'directory' => 'uploads',

];
