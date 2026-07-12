<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\HostNormalizer;
use BackedEnum;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Answers "is this host registered, and to which tenant status?" for the
 * registered-domain gate (EnsureRegisteredDomain), backed by a cache so the DB
 * is not hit on every request.
 *
 * The decision is cached per normalised host. A registered host stores
 * "<tenant_id>|<status>"; an unknown host is negative-cached ("none") on a
 * shorter TTL to blunt junk traffic. The Tenant/TenantDomain observers call
 * forgetTenant()/forgetHost() so the cache is refreshed the moment a domain is
 * added, updated, deleted, or a tenant is activated/deactivated.
 *
 * It also clears the sibling TenantResolver cache for the same host, so the two
 * caches can never disagree about which tenant a host maps to (a stale resolver
 * entry could otherwise route a re-pointed domain to the wrong tenant).
 */
class TenantDomainRegistry
{
    private const NEGATIVE = 'none';

    /**
     * @return array{id:int,status:string}|null null = host not registered
     */
    public function lookup(string $host): ?array
    {
        $host = HostNormalizer::normalize($host);

        if ($host === '') {
            return null;
        }

        $cache = $this->cache();
        $key = $this->guardKey($host);
        $cached = $cache->get($key);

        if ($cached === self::NEGATIVE) {
            return null;
        }

        if (is_string($cached) && str_contains($cached, '|')) {
            [$id, $status] = explode('|', $cached, 2);

            return ['id' => (int) $id, 'status' => $status];
        }

        $result = $this->query($host);

        if ($result === null) {
            $cache->put($key, self::NEGATIVE, $this->negativeTtl());

            return null;
        }

        $cache->put($key, $result['id'].'|'.$result['status'], $this->ttl());

        return $result;
    }

    /** Drop the cached decision (both caches) for a single host. */
    public function forgetHost(string $host): void
    {
        $host = HostNormalizer::normalize($host);

        if ($host === '') {
            return;
        }

        $cache = $this->cache();
        $cache->forget($this->guardKey($host));
        $cache->forget($this->resolverKey($host));
    }

    /** Drop cached decisions for every host that could resolve to $tenant. */
    public function forgetTenant(Tenant $tenant): void
    {
        $hosts = $tenant->domains()->pluck('host')->all();
        $hosts[] = $tenant->slug.'.'.$this->baseDomain();

        foreach ($hosts as $host) {
            $this->forgetHost((string) $host);
        }
    }

    /**
     * @return array{id:int,status:string}|null
     */
    private function query(string $host): ?array
    {
        $tenantId = TenantDomain::query()
            ->whereIn('host', HostNormalizer::candidates($host))
            ->value('tenant_id');

        if ($tenantId === null) {
            $label = HostNormalizer::subdomainLabel($host, $this->baseDomain());

            if ($label !== null) {
                $tenantId = Tenant::query()->where('slug', $label)->value('id');
            }
        }

        if ($tenantId === null) {
            return null;
        }

        // Excludes soft-deleted tenants (global scope) → treated as unregistered.
        $status = Tenant::query()->whereKey($tenantId)->value('status');

        if ($status === null) {
            return null;
        }

        return [
            'id' => (int) $tenantId,
            'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
        ];
    }

    public function isActive(array $decision): bool
    {
        return $decision['status'] === TenantStatus::Active->value;
    }

    private function cache(): CacheRepository
    {
        return Cache::store(config('tenancy.cache.store'));
    }

    private function guardKey(string $host): string
    {
        return (string) config('tenancy.guard.cache_prefix', 'tenant_domain_guard:').$host;
    }

    private function resolverKey(string $host): string
    {
        return (string) config('tenancy.cache.prefix', 'tenant_resolve:').$host;
    }

    private function baseDomain(): string
    {
        return HostNormalizer::normalize((string) config('tenancy.base_domain', 'elameed.app'));
    }

    private function ttl(): int
    {
        return (int) config('tenancy.guard.cache_ttl', config('tenancy.cache.ttl', 3600));
    }

    private function negativeTtl(): int
    {
        return (int) config('tenancy.guard.negative_cache_ttl', config('tenancy.cache.negative_ttl', 60));
    }
}
