<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Astra Mail API Base URL
    |--------------------------------------------------------------------------
    | The base URL of the Astra internal mail service.
    | Example: https://mail.internal.astraworld.com
    */
    'base_url' => env('MAIL_ASTRA_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Astra Mail Client Code
    |--------------------------------------------------------------------------
    | Sent as the X-Client-Code header on every request.
    */
    'client_code' => env('MAIL_ASTRA_CLIENT_CODE', ''),

    /*
    |--------------------------------------------------------------------------
    | TLS Verification
    |--------------------------------------------------------------------------
    | Set to false only in internal/dev environments.
    | Strongly recommended to set to true in production.
    */
    'verify_tls' => env('MAIL_ASTRA_VERIFY_TLS', false),

];
