<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Documents of registered users live in this disk. The anonymous flow never
    | writes the uploaded files to disk: everything happens in memory.
    |
    */

    'disk' => env('FIRMA_DISK', env('FILESYSTEM_DISK', 'local')),

    'documents_prefix' => 'documents',

    /*
    |--------------------------------------------------------------------------
    | Upload limits (KB)
    |--------------------------------------------------------------------------
    */

    'max_pdf_kb' => (int) env('FIRMA_MAX_PDF_KB', 20480),
    'max_certificate_kb' => (int) env('FIRMA_MAX_CERTIFICATE_KB', 512),
    'max_image_kb' => (int) env('FIRMA_MAX_IMAGE_KB', 4096),

    /*
    |--------------------------------------------------------------------------
    | Signers
    |--------------------------------------------------------------------------
    */

    'min_signers' => 1,
    'max_signers' => 20,

    /*
    |--------------------------------------------------------------------------
    | Signature stamp
    |--------------------------------------------------------------------------
    |
    | Placement is expressed in PDF points, with the origin at the top-left
    | corner of the page as displayed (the client sends screen coordinates).
    | These are the accepted bounds for a stamp.
    |
    */

    'signature' => [
        'reason' => env('FIRMA_DEFAULT_REASON', 'Firma digital del documento'),
        'location' => env('FIRMA_DEFAULT_LOCATION'),
        'min_width_pt' => 80.0,
        'min_height_pt' => 30.0,
        'max_width_pt' => 560.0,
        'max_height_pt' => 320.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature validation
    |--------------------------------------------------------------------------
    |
    | The verification endpoint always checks integrity and the CMS signature.
    | When a PEM bundle is configured it is also used as the trust store, so
    | `trusted` reports whether the signer chains to one of those roots.
    |
    */

    'trust_store_path' => env('FIRMA_TRUST_STORE_PATH'),

];
