<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate a route to holders of a granular permission in the resolved tenant (M18).
 * Usage: ->middleware('permission:students'). Runs inside a role:teacher,assistant
 * group, so the caller is already an active teacher or assistant of the tenant.
 *
 * Teachers (academy owners) pass every check; assistants pass only for the
 * permissions the teacher granted on their membership. See Permission catalog +
 * TenantUser::hasPermission().
 */
class EnsurePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $tenant = $this->context->tenant();
        $membership = ($user !== null && $tenant !== null) ? $user->membershipFor($tenant) : null;

        if ($membership === null || ! $membership->isActive() || ! $membership->hasPermission($permission)) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
