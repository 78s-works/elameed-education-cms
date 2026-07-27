<?php

$baseDomain = env('TENANCY_BASE_DOMAIN', 'elameed.app');

return [

    /*
    |--------------------------------------------------------------------------
    | Custom domains (M02 / Phase 1.5)
    |--------------------------------------------------------------------------
    |
    | A teacher can attach their own domain (e.g. mr-ahmed.com) to their academy.
    | The host is stored in `tenant_domains` (type=custom) and resolves to the
    | tenant exactly like a subdomain. TLS + verification are handled by
    | Cloudflare-for-SaaS in production — that provisioning is the future seam;
    | for now the API records the row + returns the DNS record the teacher must
    | set (`cname_target`), and CORS trusts the origin once it resolves.
    |
    */

    'custom_enabled' => (bool) env('DOMAINS_CUSTOM_ENABLED', true),

    // The CNAME target the teacher points their domain at (the shared origin /
    // Cloudflare-for-SaaS fallback hostname).
    'cname_target' => env('DOMAINS_CNAME_TARGET', 'connect.'.$baseDomain),

    // Max custom domains a single tenant may register.
    'max_per_tenant' => (int) env('DOMAINS_MAX_PER_TENANT', 5),

];
