<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform base domain
    |--------------------------------------------------------------------------
    |
    | Tenants are reachable at "<slug>.<base_domain>" in Phase 1 (subdomains).
    | A request whose Host is a subdomain of this domain has its first label
    | parsed as the tenant slug when no explicit tenant_domains row matches.
    | See 02_Architecture.md §4.3 (request → tenant resolution).
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN', 'elameed.app'),

    /*
    |--------------------------------------------------------------------------
    | Dev / tooling tenant override header
    |--------------------------------------------------------------------------
    |
    | For local development and platform-admin tooling an explicit header can
    | override host-based resolution (04_API_Specification.md §1). This is only
    | honoured outside production so a spoofed header can never pick a tenant
    | on a live host.
    |
    */

    'header' => env('TENANCY_HEADER', 'X-Tenant'),

    'allow_header_override' => env('TENANCY_ALLOW_HEADER_OVERRIDE', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Resolution cache
    |--------------------------------------------------------------------------
    |
    | host → tenant_id rarely changes, so it is cached aggressively; unknown
    | hosts are negative-cached (shorter TTL) to blunt junk traffic. TTLs are
    | in seconds. Uses the default cache store (Redis in production).
    |
    */

    'cache' => [
        'store' => env('TENANCY_CACHE_STORE', null), // null = default cache store
        'prefix' => 'tenant_resolve:',
        'ttl' => (int) env('TENANCY_CACHE_TTL', 3600),
        'negative_ttl' => (int) env('TENANCY_NEGATIVE_CACHE_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Row-Level Security session variable
    |--------------------------------------------------------------------------
    |
    | The Postgres GUC that RLS policies read (current_setting('app.tenant_id')).
    | Set at the start of every tenant-scoped request and reset on release —
    | pooled/persistent connections (Octane, queue workers) can otherwise leak
    | one tenant's value into the next request. See 02_Architecture.md §4.2 and
    | 06_Engineering_Guide.md §8.
    |
    */

    'rls_session_var' => 'app.tenant_id',

];
