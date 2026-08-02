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

    /**
     * Enum value for a stored type, tolerant of legacy/out-of-enum strings
     * (e.g. an imported 'domain'). A read-only surface like the admin console
     * or the teacher's domain list must degrade to the raw string rather than
     * throw a ValueError on the enum cast.
     */
    public static function present(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::tryFrom($raw)?->value ?? $raw;
    }
}
