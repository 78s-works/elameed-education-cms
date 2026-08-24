<?php

namespace App\Modules\Identity\Support;

/**
 * The ONE guard our roles and permissions live under (M20).
 *
 * Never read this from `config('auth.defaults.guard')`. Laravel's Authenticate
 * middleware calls Auth::shouldUse(), which WRITES that config key — so inside
 * an authenticated API request the "default guard" is `sanctum`, while the
 * permission catalog is seeded from the console under `web`. Roles created
 * during a request were landing on a different guard from the permissions they
 * were meant to carry, which silently produces a role that can hold nothing.
 *
 * Spatie matches roles to permissions, and users to roles, by guard name. One
 * literal, used everywhere, is the only way that match is guaranteed.
 */
final class RbacGuard
{
    public const NAME = 'web';

    public static function name(): string
    {
        return self::NAME;
    }
}
