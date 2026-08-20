<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Issues / clears the auth cookies used by the httpOnly Bearer bridge.
 *
 *  - The token cookie (httpOnly) carries the Sanctum personal-access token; it is
 *    read back into the Authorization header by AuthenticateWithTokenCookie.
 *  - The CSRF cookie (readable) is a double-submit token the SPA echoes in the
 *    X-CSRF-Token header on mutating requests; verified by VerifyCookieCsrf.
 *
 * The api middleware group has NO EncryptCookies, so both cookies round-trip as
 * plaintext (the token IS the credential; httpOnly is what protects it from JS).
 */
class AuthCookie
{
    /** Attach the token + CSRF cookies to a login/verify/magic response. */
    public static function issue(JsonResponse $response, string $token): JsonResponse
    {
        return $response
            ->withCookie(self::make((string) config('authcookie.name'), $token, true))
            ->withCookie(self::make((string) config('authcookie.csrf_name'), Str::random(40), false));
    }

    /** Expire both cookies on logout. */
    public static function forget(JsonResponse $response): JsonResponse
    {
        $path = (string) config('authcookie.path', '/');
        $domain = config('authcookie.domain');

        return $response
            ->withCookie(Cookie::forget((string) config('authcookie.name'), $path, $domain))
            ->withCookie(Cookie::forget((string) config('authcookie.csrf_name'), $path, $domain));
    }

    private static function make(string $name, string $value, bool $httpOnly): SymfonyCookie
    {
        return cookie(
            name: $name,
            value: $value,
            minutes: (int) config('authcookie.lifetime'),
            path: (string) config('authcookie.path', '/'),
            domain: config('authcookie.domain'),
            secure: (bool) config('authcookie.secure'),
            httpOnly: $httpOnly,
            raw: false,
            sameSite: (string) config('authcookie.same_site', 'lax'),
        );
    }
}
