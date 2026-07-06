<?php

namespace App\Modules\PlatformAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gate for the platform-admin console. Admin endpoints are cross-tenant and run
 * outside the `tenant` middleware (04_API_Spec §2 — "/admin/* not tenant-scoped").
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isPlatformAdmin()) {
            throw new AccessDeniedHttpException('Platform administrators only.');
        }

        return $next($request);
    }
}
