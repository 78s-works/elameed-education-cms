<?php

namespace App\Modules\PlatformAdmin\Http\Middleware;

use App\Modules\Tenancy\Support\CentralHosts;
use App\Modules\Tenancy\Support\HostNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the platform-admin console to a central/admin host. The admin API is
 * cross-tenant and must never be served from a teacher academy's domain — a
 * teacher (or their visitors) must not be able to reach /admin/* by pointing
 * their own subdomain or custom domain at the platform.
 *
 * Applied ahead of auth on the /admin/* group (bootstrap/app.php alias `central`):
 * a request on any non-central host is answered with 404 — the same response a
 * nonexistent route gives — so the console's existence is not disclosed off-host,
 * and a valid platform-admin token cannot be replayed against a tenant domain.
 *
 * Anti-spoofing mirrors EnsureRegisteredDomain: the host comes from
 * Request::getHost() (X-Forwarded-Host is honoured only from trusted proxies),
 * and CentralHosts is the shared source of truth for what counts as central, so
 * this admin gate and the tenant gate can never disagree. See 02_Architecture.md §4.3.
 */
class EnsureCentralHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = HostNormalizer::normalize($request->getHost());

        if (! CentralHosts::matches($host)) {
            abort(Response::HTTP_NOT_FOUND, 'Not found.');
        }

        return $next($request);
    }
}
