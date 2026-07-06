<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps an incoming request to a tenant (02_Architecture.md §4.3):
 *
 *   1. (dev/tooling only) an explicit X-Tenant: <slug> header overrides.
 *   2. exact Host match in tenant_domains (custom domains AND subdomains).
 *   3. Host is "<label>.<base_domain>" → treat <label> as the tenant slug.
 *   4. otherwise unresolved (→ platform site / 404, decided by the caller).
 *
 * host → tenant is cached aggressively; unknown hosts are negative-cached to
 * blunt junk traffic. Uses the cache abstraction (Redis in production).
 */
class TenantResolver
{
    private const NEGATIVE = 'none';

    public function resolve(Request $request): ?Tenant
    {
        // 1. Explicit override for local dev / platform-admin tooling.
        if ($this->headerOverrideAllowed()) {
            $slug = $request->header((string) config('tenancy.header', 'X-Tenant'));

            if (is_string($slug) && $slug !== '') {
                return $this->findBySlug($slug);
            }
        }

        // 2–4. Host-based resolution.
        $host = Str::lower($request->getHost());

        if ($host === '') {
            return null;
        }

        return $this->resolveByHost($host);
    }

    private function resolveByHost(string $host): ?Tenant
    {
        $cache = $this->cache();
        $key = $this->cacheKey($host);

        $cached = $cache->get($key);

        if ($cached === self::NEGATIVE) {
            return null;
        }

        if (is_int($cached)) {
            return Tenant::find($cached);
        }

        $tenantId = $this->lookupHost($host);

        if ($tenantId === null) {
            $cache->put($key, self::NEGATIVE, (int) config('tenancy.cache.negative_ttl', 60));

            return null;
        }

        $cache->put($key, $tenantId, (int) config('tenancy.cache.ttl', 3600));

        return Tenant::find($tenantId);
    }

    /** Resolve a host to a tenant id via an exact domain row or the subdomain label. */
    private function lookupHost(string $host): ?int
    {
        $domain = TenantDomain::query()->where('host', $host)->first();

        if ($domain !== null) {
            return (int) $domain->tenant_id;
        }

        $label = $this->subdomainLabel($host);

        if ($label !== null) {
            return Tenant::query()->where('slug', $label)->value('id');
        }

        return null;
    }

    /** Return the first label if $host is a direct subdomain of the base domain. */
    private function subdomainLabel(string $host): ?string
    {
        $base = Str::lower((string) config('tenancy.base_domain', 'elameed.app'));

        if ($base === '' || ! Str::endsWith($host, '.'.$base)) {
            return null;
        }

        $label = Str::beforeLast($host, '.'.$base);

        // Only a single-label subdomain ("ahmed"), not "a.b" or the apex itself.
        return ($label !== '' && ! Str::contains($label, '.')) ? $label : null;
    }

    private function findBySlug(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', Str::lower($slug))->first();
    }

    private function headerOverrideAllowed(): bool
    {
        return (bool) config('tenancy.allow_header_override', false);
    }

    private function cache(): CacheRepository
    {
        return Cache::store(config('tenancy.cache.store'));
    }

    private function cacheKey(string $host): string
    {
        return config('tenancy.cache.prefix', 'tenant_resolve:').$host;
    }
}
