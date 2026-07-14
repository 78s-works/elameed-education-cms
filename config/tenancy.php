<?php

$baseDomain = env('TENANCY_BASE_DOMAIN', 'elameed.app');

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

    'base_domain' => $baseDomain,

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

    /*
    |--------------------------------------------------------------------------
    | Registered-domain gate (EnsureRegisteredDomain middleware)
    |--------------------------------------------------------------------------
    |
    | Every tenant-scoped request must arrive on a host that maps to an ACTIVE
    | tenant — a `tenant_domains` row, or "<slug>.<base_domain>". Unknown or
    | suspended hosts are rejected before any tenant routing runs. The host is
    | read via Request::getHost() (X-Forwarded-Host is only trusted from trusted
    | proxies) so a spoofed Host cannot bypass the gate. See 02_Architecture.md
    | §4.3.
    |
    */

    'guard' => [

        // Platform/central/admin hosts allowed through without a tenant lookup.
        // The base-domain apex is always treated as central in addition to these.
        'central_domains' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'admin.'.$baseDomain))
        ))),

        // Local/dev hosts exempt from the gate. Only honoured outside production,
        // so a spoofed "Host: localhost" cannot bypass the gate on a live host.
        'local_domains' => ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'],
        'local_suffixes' => ['.localhost', '.test'],
        'trust_local_domains' => env('TENANCY_TRUST_LOCAL_DOMAINS', env('APP_ENV') !== 'production'),

        // Route names / path globs the gate must never block (health checks etc.).
        'except_routes' => [],
        'except_paths' => ['up'],

        // Response codes: unknown host vs registered-but-inactive tenant.
        'unregistered_status' => (int) env('TENANCY_UNREGISTERED_STATUS', 404),
        'inactive_status' => (int) env('TENANCY_INACTIVE_STATUS', 403),

        // Decision cache (seconds). Reuses the tenancy cache store; invalidated by
        // the Tenant/TenantDomain observers on any add/update/delete/activate.
        'cache_prefix' => 'tenant_domain_guard:',
        'cache_ttl' => (int) env('TENANCY_DOMAIN_CACHE_TTL', 3600),
        'negative_cache_ttl' => (int) env('TENANCY_DOMAIN_NEGATIVE_CACHE_TTL', 60),
    ],

];

