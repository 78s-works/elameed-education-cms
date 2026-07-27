<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Enums\TenantDomainType;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\CentralHosts;
use App\Modules\Tenancy\Support\HostNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers, lists and removes a tenant's custom domains (M02, custom domains
 * Part 2). `tenant_domains` is a GLOBAL model (read during resolution, no
 * BelongsToTenant), so every read/write here is explicitly constrained to the
 * caller's tenant — never rely on a global scope.
 *
 * Once a `custom` row exists the TenantResolver + registered-domain gate resolve
 * that host to the tenant automatically (the TenantDomain observer busts the
 * resolution caches on save/delete). TLS + ownership verification are handled by
 * Cloudflare-for-SaaS in production; this service records the row and returns the
 * DNS record the teacher must publish (`domains.cname_target`).
 */
class CustomDomainService
{
    /** Register a custom host for the tenant. */
    public function register(int $tenantId, string $rawHost, bool $primary = false): TenantDomain
    {
        $host = HostNormalizer::normalize($rawHost);

        $this->assertRegisterable($tenantId, $host);

        $domain = new TenantDomain([
            'tenant_id' => $tenantId,
            'host' => $host,
            'type' => TenantDomainType::Custom->value,
            'is_primary' => false,
            'ssl_status' => 'pending',
        ]);

        DB::transaction(function () use ($domain, $tenantId, $primary): void {
            $domain->save();

            if ($primary) {
                $this->makePrimary($domain, $tenantId);
            }
        });

        return $domain;
    }

    /** Promote a domain to the tenant's primary host (demotes the others). */
    public function makePrimary(TenantDomain $domain, int $tenantId): void
    {
        TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->whereKeyNot($domain->getKey())
            ->update(['is_primary' => false]);

        $domain->forceFill(['is_primary' => true])->save();
    }

    public function remove(TenantDomain $domain): void
    {
        $domain->delete();
    }

    /** The DNS record the teacher must publish to activate the domain. */
    public function dnsInstructions(TenantDomain $domain): array
    {
        return [
            'type' => 'CNAME',
            'name' => $domain->host,
            'value' => (string) config('domains.cname_target'),
            'note' => 'Add this record at your DNS provider. Verification and SSL are issued automatically once it propagates.',
        ];
    }

    private function assertRegisterable(int $tenantId, string $host): void
    {
        if (! config('domains.custom_enabled', true)) {
            throw ValidationException::withMessages(['host' => 'Custom domains are not enabled for your academy.']);
        }

        if ($host === '' || ! $this->hasValidFormat($host)) {
            throw ValidationException::withMessages(['host' => 'Enter a valid domain name (e.g. academy.example.com).']);
        }

        // The platform owns central hosts, the base-domain apex and every
        // *.<base_domain> subdomain — a teacher can never claim one as "custom".
        $base = HostNormalizer::normalize((string) config('tenancy.base_domain', 'elameed.app'));
        if (CentralHosts::matches($host) || $host === $base || str_ends_with($host, '.'.$base)) {
            throw ValidationException::withMessages(['host' => 'This domain is managed by the platform and cannot be registered.']);
        }

        // Host is globally unique (a host maps to exactly one tenant).
        if (TenantDomain::query()->whereIn('host', HostNormalizer::candidates($host))->exists()) {
            throw ValidationException::withMessages(['host' => 'This domain is already registered.']);
        }

        $max = (int) config('domains.max_per_tenant', 5);
        $current = TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('type', TenantDomainType::Custom->value)
            ->count();

        if ($current >= $max) {
            throw ValidationException::withMessages(['host' => "You can register at most {$max} custom domains."]);
        }
    }

    private function hasValidFormat(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $host);
    }
}
