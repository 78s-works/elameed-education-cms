<?php

namespace App\Modules\Tenancy\Enums;

/**
 * How a host maps to a tenant. Phase 1 ships `subdomain` only; `custom` (via
 * Cloudflare for SaaS) arrives in Phase 1.5. See 02_Architecture.md §4.4.
 */
enum TenantDomainType: string
{
    case Subdomain = 'subdomain';
    case Custom = 'custom';
}
