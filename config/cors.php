<?php

/*
 * The platform's OWN hosts — the SPA host and the API host — are the central
 * domains from config/tenancy.php. They are not tenant domains, so
 * DynamicTenantCors (which trusts an academy's own domain once it resolves)
 * never covers them, and the admin console would depend on someone remembering
 * to repeat the SPA host in CORS_ALLOWED_ORIGINS. Derive them here instead, so
 * the console works on a fresh deployment and cannot drift from the host config
 * it is already served on.
 */
$centralOrigins = array_map(
    static fn (string $host): string => 'https://'.$host,
    array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        array_merge(
            explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', '')),
            [(string) env('TENANCY_BASE_DOMAIN', '')],
        )
    )))
);

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

    'allowed_origins' => array_values(array_unique(array_merge(
        array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
        ))),
        $centralOrigins,
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
