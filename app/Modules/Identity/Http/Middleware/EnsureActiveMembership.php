<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Enforces an ACTIVE membership in the current tenant on every authenticated
 * request (not just at login). This makes a teacher's "suspend" take effect
 * immediately — a suspended student is blocked from all tenant endpoints,
 * including obtaining new playback tokens — while remaining tenant-scoped, so it
 * never affects the same person's access to a different academy. Platform admins
 * are exempt.
 */
class EnsureActiveMembership
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        if ($tenant !== null && $user !== null && ! $user->isPlatformAdmin()) {
            $membership = $user->membershipFor($tenant);

            if ($membership === null || ! $membership->isActive()) {
                throw new AccessDeniedHttpException(__('Your access to this academy is not active.'));
            }
        }

        return $next($request);
    }
}
