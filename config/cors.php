<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | In production the SPA and API are same-origin (each host proxies /api to the
    | backend), so CORS is mostly moot there. It still matters for local dev and
    | during transition. Auth now rides an httpOnly cookie, so credentials support
    | is ON — which means `allowed_origins` must be an explicit, reflected list
    | (never `*`). DynamicTenantCors reflects each validated tenant origin.
    | `X-Tenant` / `X-CSRF-Token` are covered by the `*` allowed headers.
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

    // Auth cookie is sent cross-origin in dev/transition → credentials ON. Safe
    // because origins are an explicit reflected list (DynamicTenantCors), never
    // '*'. In production the SPA is same-origin so no credentialed CORS occurs.
    'supports_credentials' => true,

];
