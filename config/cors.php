<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The Vue SPA runs on a different origin (its own host:port), so the browser
    | enforces CORS on every API call. Production origins are listed explicitly in
    | CORS_ALLOWED_ORIGINS; local development also allows any private-LAN origin
    | via the pattern below (dev servers move between LAN IPs). Auth is via Sanctum
    | BEARER tokens (not cookies), so credentials support is off and a specific
    | origin list is fine. `X-Tenant` is covered by the `*` allowed headers.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ))),

    'allowed_origins_patterns' => array_values(array_filter([
        // DEV ONLY: any private-LAN host (localhost, 10/8, 172.16/12, 192.168/16)
        // on any port — so http://192.168.x.x:5173 works without editing the env
        // each time the dev machine's IP changes. Never active in production.
        env('APP_ENV') !== 'production'
            ? '#^https?://(localhost|127\.0\.0\.1|10(\.\d{1,3}){3}|172\.(1[6-9]|2\d|3[01])(\.\d{1,3}){2}|192\.168(\.\d{1,3}){2})(:\d+)?$#'
            : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer tokens, not cookies → no credentials. (If you switch to Sanctum
    // SPA cookie mode, set this true, drop any '*' origin, and configure
    // SANCTUM_STATEFUL_DOMAINS.)
    'supports_credentials' => false,

];
