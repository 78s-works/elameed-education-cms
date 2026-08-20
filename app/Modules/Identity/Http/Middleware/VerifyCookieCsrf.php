<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Double-submit CSRF guard for requests authenticated via the ambient auth
 * cookie. Because the cookie is now sent automatically by the browser, a
 * mutating request must also carry a CSRF token (the readable XSRF-TOKEN cookie
 * echoed in the X-CSRF-Token header) that a cross-site attacker cannot forge.
 *
 * Enforced ONLY when AuthenticateWithTokenCookie flagged `auth_via_cookie` — so
 * Bearer-header clients and Sanctum::actingAs() tests are never affected.
 * SameSite=Lax already blocks the cross-site case; this is defence-in-depth for
 * the same-site edge.
 */
class VerifyCookieCsrf
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $viaCookie = (bool) $request->attributes->get('auth_via_cookie', false);

        if ($viaCookie && ! in_array($request->method(), self::READ_METHODS, true)) {
            $cookie = (string) ($request->cookie((string) config('authcookie.csrf_name')) ?? '');
            $header = (string) ($request->header('X-CSRF-Token') ?? $request->header('X-XSRF-Token') ?? '');

            if ($cookie === '' || ! hash_equals($cookie, $header)) {
                throw new HttpException(419, __('CSRF token mismatch.'));
            }
        }

        return $next($request);
    }
}
