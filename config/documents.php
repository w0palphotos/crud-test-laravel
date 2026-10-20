<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Disk
    |--------------------------------------------------------------------------
    |
    | Where the documents filed with a sale are kept.
    |
    | A different disk from the images, and a different bucket, because privacy
    | is a property of a bucket and not of a directory inside one. A document
    | filed with a sale is a faktur, a surat jalan, a proof of delivery — and
    | the images beside it are a photograph of a part. They do not belong under
    | the same roof, and the second one cannot be made private after the fact.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Accepted Types
    |--------------------------------------------------------------------------
    |
    | What may be filed.
    |
    | Three types, and the list is here rather than in the request so that adding
    | one is a matter of configuration. The types are the ones a sales desk
    | actually receives: a document from a supplier or a customer, in whatever
    | form it was sent.
    |
    | Note that a spreadsheet is deliberately absent. The import flow already
    | reads those, and reading is not filing: a file that was read to become
    | sales is not a document anybody keeps.
    |
    */

    'mimetypes' => [
        'application/pdf',
        'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],

    /*
    |--------------------------------------------------------------------------
    | Size Limit
    |--------------------------------------------------------------------------
    |
    | In kilobytes. Documents are read by people, not by a model, so the limit
    | is generous: a scanned faktur at two thousand kilobytes is ordinary, and
    | anything larger is a spreadsheet or a video that came to the wrong desk.
    |
    */

    'max_kilobytes' => (int) env('DOCUMENTS_MAX_KILOBYTES', 5120),

    /*
    |--------------------------------------------------------------------------
    | Upload Path
    |--------------------------------------------------------------------------
    |
    | Every document goes into this one directory, under a generated name.
    |
    | Not a client-supplied folder: a path assembled from input is how an upload
    | escapes its directory and lands somewhere else. One directory means there is
    | nothing for a caller to aim at, and a UUID filename cannot collide or be
    | guessed from the name of the person who filed it.
    |
    */

    'directory' => 'sale-documents',

    /*
    |--------------------------------------------------------------------------
    | Link Lifetime
    |--------------------------------------------------------------------------
    |
    | How long a link to a document works, in minutes.
    |
    | Long enough to open a document, read it, and pass the link on inside the
    | desk. Short enough that a link left in a chat is not a document that
    | stays open to anybody who finds it later.
    |
    */

    'signed_url_minutes' => (int) env('DOCUMENTS_SIGNED_URL_MINUTES', 15),

];
