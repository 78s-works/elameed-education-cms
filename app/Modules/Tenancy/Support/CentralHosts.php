<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Str;

/**
 * Classifies a request host as a platform/central host — the admin console, the
 * base-domain apex, or any configured central domain — as opposed to a teacher
 * academy (tenant) host. Outside production, trusted local-dev hosts (localhost,
 * *.test, bare IPs) count as central too so local tooling keeps working.
 *
 * Single source of truth shared by EnsureRegisteredDomain (which skips the
 * tenant gate for these hosts) and EnsureCentralHost (which restricts the
 * platform-admin console to them), so the two can never drift on what counts as
 * "not a tenant domain". See 02_Architecture.md §4.3.
 */
final class CentralHosts
{
    /** Whether the given (already normalised) host is a platform/central host. */
    public static function matches(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        // Local/dev hosts — trusted only outside production, so a spoofed
        // "Host: localhost" cannot reach a central surface on a live deployment.
        if ((bool) config('tenancy.guard.trust_local_domains', false)) {
            // Tenants are always reached by DNS name, never a bare IP. In dev the
            // app is commonly served on a LAN IP (or 127.0.0.1), so exempt any IP
            // literal — but only here, where local hosts are trusted.
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                return true;
            }

            if (in_array($host, self::normalizedList('tenancy.guard.local_domains'), true)) {
                return true;
            }

            foreach ((array) config('tenancy.guard.local_suffixes', []) as $suffix) {
                $suffix = strtolower((string) $suffix);

                if ($suffix !== '' && Str::endsWith($host, $suffix)) {
                    return true;
                }
            }
        }

        // The base-domain apex is always central; plus any configured hosts.
        if ($host === self::baseDomain()) {
            return true;
        }

        return in_array($host, self::normalizedList('tenancy.guard.central_domains'), true);
    }

    /** @return list<string> */
    private static function normalizedList(string $key): array
    {
        return array_values(array_map(
            static fn ($host): string => HostNormalizer::normalize((string) $host),
            (array) config($key, [])
        ));
    }

    private static function baseDomain(): string
    {
        return HostNormalizer::normalize((string) config('tenancy.base_domain', 'elameed.app'));
    }
}
