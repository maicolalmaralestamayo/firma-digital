<?php

return [

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',
        'X-Signature-Sha256',
        'X-Certificate-Cn',
        'X-Certificate-Serial',
        'X-Signed-At',
        'X-Document-Pages',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,

];
