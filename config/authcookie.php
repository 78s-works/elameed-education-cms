<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth token cookie (httpOnly Bearer bridge)
    |--------------------------------------------------------------------------
    |
    | The SPA no longer stores the Sanctum token in localStorage (XSS-exfiltration
    | risk). Instead the API sets the token in an httpOnly, Secure, SameSite=Lax
    | cookie; `AuthenticateWithTokenCookie` reads it back into the Authorization
    | header before `auth:sanctum`. Because the SPA and API are same-origin in
    | production (each custom domain's Cloudflare Worker proxies to front.edu,
    | whose web server proxies /api to the backend), the cookie is first-party and
    | Lax works on every browser — no third-party-cookie fragility.
    |
    | The token is ALSO still returned in the login/verify JSON body so non-browser
    | Bearer clients keep working; the browser simply ignores the body token.
    |
    */

    // The httpOnly cookie carrying the Sanctum personal-access token.
    'name' => env('AUTH_TOKEN_COOKIE', 'elameed_token'),

    // The readable (non-httpOnly) double-submit CSRF cookie the SPA echoes back
    // in the `X-CSRF-Token` header on mutating requests.
    'csrf_name' => env('AUTH_CSRF_COOKIE', 'XSRF-TOKEN'),

    // Lifetime in MINUTES. Sanctum PATs here don't expire/rotate, so mirror the
    // old localStorage persistence with a long-lived cookie (default 1 year).
    'lifetime' => (int) env('AUTH_COOKIE_LIFETIME', 525600),

    // Secure flag. Must be true in production (HTTPS). Defaults on by APP_ENV so
    // local http dev (Vite proxy on http://localhost) still sets the cookie.
    'secure' => (bool) env('AUTH_COOKIE_SECURE', env('APP_ENV') === 'production'),

    // SameSite policy. `lax` is correct once the API is same-origin with the SPA.
    'same_site' => env('AUTH_COOKIE_SAMESITE', 'lax'),

    'path' => '/',

    // Host-only by default (null): each host — front.edu.78sworks.io and every
    // custom domain reached through its Worker — binds its own first-party cookie.
    'domain' => env('AUTH_COOKIE_DOMAIN') ?: null,

];
