<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | CORS is handled by App\Support\Http\HandleDynamicCors (it replaces the
    | framework's static HandleCors). `allowed_origins` below is still the source
    | of the ALWAYS-allowed origins — the shared SPA and local dev. On top of
    | that list the middleware also allows any subdomain of tenancy.base_domain
    | and any registered, active tenant custom domain (tenant_domains). So this
    | list is only for fixed, non-tenant origins; tenant domains are dynamic.
    | Auth is via Sanctum BEARER tokens (not cookies), so credentials stay off.
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
