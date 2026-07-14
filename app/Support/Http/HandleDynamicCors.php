<?php

namespace App\Support\Http;

use App\Modules\Tenancy\Services\TenantDomainRegistry;
use App\Modules\Tenancy\Support\HostNormalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic, multi-tenant CORS.
 *
 * A browser request may originate from the shared SPA, a per-tenant subdomain of
 * the platform base domain, or a tenant's OWN custom domain (registered in
 * tenant_domains). Because `Access-Control-Allow-Origin` may carry only one
 * value, we reflect the caller's Origin — but only after confirming it is
 * allowed by one of:
 *
 *   1. an explicitly configured origin (cors.allowed_origins — the main SPA / local dev);
 *   2. the platform base domain or any of its subdomains (`*.<base_domain>`);
 *   3. a registered, ACTIVE tenant domain (DB, cached via TenantDomainRegistry).
 *
 * Replaces Laravel's static HandleCors and runs as the first global middleware,
 * so the CORS preflight (OPTIONS) is answered before the domain gate or auth can
 * reject it. Auth is via Bearer tokens (no cookies), so credentials are never
 * allowed and reflecting a specific origin is safe.
 */
class HandleDynamicCors
{
    public function __construct(private readonly TenantDomainRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = $origin !== null && $this->originAllowed($origin);

        // Short-circuit the CORS preflight here — before tenant/auth middleware,
        // which would otherwise 401/404 an unauthenticated OPTIONS request.
        if ($request->isMethod('OPTIONS')) {
            $response = response('', Response::HTTP_NO_CONTENT);

            return $allowed ? $this->decorate($response, $origin, $request) : $response;
        }

        $response = $next($request);

        return $allowed ? $this->decorate($response, $origin, $request) : $response;
    }

    private function originAllowed(string $origin): bool
    {
        // 1) Explicitly configured origins (main SPA, local dev). '*' = any.
        foreach ((array) config('cors.allowed_origins', []) as $configured) {
            if ($configured === '*' || strcasecmp($origin, (string) $configured) === 0) {
                return true;
            }
        }

        $host = HostNormalizer::normalize((string) parse_url($origin, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        // 2) The platform base domain and any subdomain of it (e.g. *.edu.78sworks.io).
        $base = HostNormalizer::normalize((string) config('tenancy.base_domain', ''));

        if ($base !== '' && ($host === $base || Str::endsWith($host, '.'.$base))) {
            return true;
        }

        // 3) A registered, active tenant custom domain (cached DB lookup).
        $decision = $this->registry->lookup($host);

        return $decision !== null && $this->registry->isActive($decision);
    }

    private function decorate(Response $response, string $origin, Request $request): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            $request->headers->get('Access-Control-Request-Headers')
                ?: 'Authorization, Content-Type, Accept, Origin, X-Tenant, X-Requested-With',
        );
        $response->headers->set('Access-Control-Max-Age', '3600');

        // ACAO varies by caller — stop caches/proxies from serving one tenant's
        // origin header to another.
        $vary = $response->headers->get('Vary');
        $response->headers->set('Vary', $vary ? $vary.', Origin' : 'Origin');

        return $response;
    }
}
