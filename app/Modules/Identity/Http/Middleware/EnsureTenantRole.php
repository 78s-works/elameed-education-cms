<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate a route to users holding a given role in the resolved tenant.
 * Usage: ->middleware('role:teacher'). Runs after auth:sanctum + tenant
 * resolution. Platform admins bypass (they operate every tenant).
 *
 * Granular per-assistant permissions are P1.5; for P1 this role check is the
 * authorization primitive.
 */
class EnsureTenantRole
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        $tenant = $this->context->tenant();

        if ($tenant === null) {
            throw new AccessDeniedHttpException('No academy resolved for this request.');
        }

        $membership = $user->membershipFor($tenant);

        if ($membership === null
            || ! $membership->isActive()
            || ! in_array($membership->role->value, $roles, true)) {
            throw new AccessDeniedHttpException('You do not have access to this academy resource.');
        }

        return $next($request);
    }
}
