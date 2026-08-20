<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges the httpOnly auth cookie into a Bearer Authorization header so
 * `auth:sanctum` authenticates browser requests without the SPA ever touching
 * the token in JavaScript (XSS-exfiltration defence).
 *
 * Only fills in when no Authorization header is already present, so real Bearer
 * clients (mobile / API integrations / tests) are untouched. Flags the request
 * so VerifyCookieCsrf knows this request authenticated via the ambient cookie
 * and must carry a CSRF token.
 *
 * Runs in the `api` group, i.e. before any route-level `auth:sanctum`.
 */
class AuthenticateWithTokenCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Authorization')) {
            $token = $request->cookie((string) config('authcookie.name'));

            if (is_string($token) && $token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
                $request->attributes->set('auth_via_cookie', true);
            }
        }

        return $next($request);
    }
}
