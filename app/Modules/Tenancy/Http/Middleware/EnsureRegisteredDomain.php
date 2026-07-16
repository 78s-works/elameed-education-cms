<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\TenantDomainRegistry;
use App\Modules\Tenancy\Support\CentralHosts;
use App\Modules\Tenancy\Support\HostNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Hard gate in front of every tenant-scoped route: the request's host must map
 * to a REGISTERED, ACTIVE tenant, otherwise it is rejected before any tenant
 * routing or data access happens.
 *
 * Runs ahead of ResolveTenant (see the `tenant` middleware group in
 * bootstrap/app.php) so an unknown or suspended host never reaches the resolver
 * or a controller. Central/admin hosts, local-dev hosts, and whitelisted routes
 * are exempt (04_API_Specification.md §1, 02_Architecture.md §4.3).
 *
 * Anti-spoofing: the host comes from Request::getHost(), which only honours
 * X-Forwarded-Host from trusted proxies (none by default), so a forged
 * Host / X-Forwarded-Host cannot make an unregistered domain pass. A malformed
 * host makes getHost() throw SuspiciousOperationException, which the framework
 * rejects before this gate ever runs.
 */
class EnsureRegisteredDomain
{
    public function __construct(private readonly TenantDomainRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isWhitelistedRoute($request)) {
            return $next($request);
        }

        // getHost() throws SuspiciousOperationException on a malformed host,
        // which the framework turns into a 404 before this point.
        $host = HostNormalizer::normalize($request->getHost());

        if ($host === '') {
            throw new BadRequestHttpException('Invalid host header.');
        }

        // Platform/central/admin + local-dev hosts are not tenant domains.
        if (CentralHosts::matches($host)) {
            return $next($request);
        }

        $decision = $this->registry->lookup($host);

        if ($decision === null) {
            abort($this->status('unregistered_status', Response::HTTP_NOT_FOUND), 'This domain is not registered.');
        }

        if (! $this->registry->isActive($decision)) {
            abort($this->status('inactive_status', Response::HTTP_FORBIDDEN), 'This academy is not currently active.');
        }

        return $next($request);
    }

    private function isWhitelistedRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name !== null && in_array($name, (array) config('tenancy.guard.except_routes', []), true)) {
            return true;
        }

        foreach ((array) config('tenancy.guard.except_paths', []) as $pattern) {
            if ($pattern !== '' && $request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function status(string $key, int $default): int
    {
        return (int) config('tenancy.guard.'.$key, $default);
    }
}
