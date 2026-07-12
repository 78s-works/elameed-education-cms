<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Str;

/**
 * Canonicalises request hosts so tenant resolution and the registered-domain
 * gate agree on a single form. Shared by TenantResolver and
 * TenantDomainRegistry so the two can never drift on how a host is matched.
 *
 * A host is normalised to: lower-cased, no port, no trailing dot, and with a
 * leading "www." stripped — so "WWW.Ahmed.Elameed.app." and "ahmed.elameed.app"
 * collapse to the same key. See 02_Architecture.md §4.3.
 */
final class HostNormalizer
{
    public static function normalize(?string $host): string
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return '';
        }

        // Strip a trailing :port, leaving bracket-less IPv6 (e.g. "::1") intact.
        if (preg_match('/^(\[[^\]]+\]|[^:]+):\d+$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Stored-host candidates to match against `tenant_domains.host` for a
     * normalised host, so a domain registered with OR without "www." resolves
     * regardless of which variant the request used.
     *
     * @return list<string>
     */
    public static function candidates(string $normalizedHost): array
    {
        if ($normalizedHost === '') {
            return [];
        }

        return array_values(array_unique([$normalizedHost, 'www.'.$normalizedHost]));
    }

    /**
     * If $host is a single-label subdomain of $baseDomain ("<label>.<base>"),
     * return <label> (the tenant slug). Multi-level hosts and the apex return
     * null.
     */
    public static function subdomainLabel(string $host, string $baseDomain): ?string
    {
        $base = self::normalize($baseDomain);

        if ($base === '' || ! Str::endsWith($host, '.'.$base)) {
            return null;
        }

        $label = Str::beforeLast($host, '.'.$base);

        return ($label !== '' && ! Str::contains($label, '.')) ? $label : null;
    }
}
