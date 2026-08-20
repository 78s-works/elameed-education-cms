<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The Vue SPA runs on a different origin (its own host:port), so the browser
    | enforces CORS on every API call. We allow the SPA origin(s) explicitly.
    | Auth is via Sanctum BEARER tokens (not cookies), so credentials support is
    | off and a specific origin list is fine. `X-Tenant` is covered by the `*`
    | allowed headers.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer tokens, not cookies → no credentials. (If you switch to Sanctum
    // SPA cookie mode, set this true, drop any '*' origin, and configure
    // SANCTUM_STATEFUL_DOMAINS.)
    'supports_credentials' => false,

];
