<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\TenantDomainRegistry;
use App\Modules\Tenancy\Support\CentralHosts;
use App\Modules\Tenancy\Support\HostNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusts the request Origin for CORS when its host is a registered, active tenant
 * host (a subdomain OR a custom domain) or a platform/dev host — instead of the
 * static localhost list. Runs BEFORE Laravel's HandleCors (prepended to the
 * global stack), reflecting the validated origin into cors.allowed_origins so the
 * real CORS middleware emits the header.
 *
 * Safe to reflect because auth is Bearer (supports_credentials = false), and the
 * origin is validated against the same tenant_domains source of truth as routing
 * — so CORS can never trust a host that wouldn't resolve to a tenant.
 */
class DynamicTenantCors
{
    public function __construct(private readonly TenantDomainRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && $origin !== '') {
            $host = HostNormalizer::normalize((string) parse_url($origin, PHP_URL_HOST));

            if ($host !== '' && $this->isTrusted($host)) {
                $origins = (array) config('cors.allowed_origins', []);

                if (! in_array($origin, $origins, true)) {
                    config(['cors.allowed_origins' => [...$origins, $origin]]);
                }
            }
        }

        return $next($request);
    }

    private function isTrusted(string $host): bool
    {
        if (CentralHosts::matches($host)) {
            return true;
        }

        $decision = $this->registry->lookup($host);

        return $decision !== null && $this->registry->isActive($decision);
    }
}
