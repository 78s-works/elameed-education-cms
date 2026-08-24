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
 * never affects the same person's access to a different academy. A platform
 * admin has no implicit membership either: their token carries no access to a
 * tenant's routes — admin work happens only via the host-pinned /admin/* console.
 */
class EnsureActiveMembership
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        if ($tenant !== null && $user !== null) {
            // Start every request from a clean authorization slate (M20). Spatie
            // answers `can` from the user's LOADED role/permission relations; if
            // this object survived a previous request for a DIFFERENT academy —
            // Octane, a queue worker, a test hitting two tenants — those relations
            // would still describe the other academy. Forgetting them here means
            // the roles are always re-read for the tenant of THIS request.
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $membership = $user->membershipFor($tenant);

            if ($membership === null || ! $membership->isActive()) {
                throw new AccessDeniedHttpException(__('Your access to this academy is not active.'));
            }
        }

        return $next($request);
    }
}
