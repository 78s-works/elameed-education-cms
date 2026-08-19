<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Support\DeviceBinding;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects an access token presented from a device other than the one it was
 * minted on. Runs AFTER `auth:sanctum` so the acting token is resolved.
 *
 * A token whose `device_id` is set must arrive with an `X-Device-Id` header
 * that hashes to it; a mismatch (or a missing header) yields a 401 and the
 * bound token is revoked, so a stolen/copied token cannot keep probing.
 *
 * Tokens with a null `device_id` (issued before device-binding shipped) are
 * allowed through — legacy grace. They bind themselves the next time the user
 * signs in.
 */
class EnsureTokenDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Read the column via getAttribute (a real method call), not the magic
        // `->device_id` property: under Sanctum::actingAs the acting token is a
        // Mockery mock whose undefined-property access is not reliably null.
        $bound = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;

        if (is_string($bound) && $bound !== '' && $bound !== DeviceBinding::hash($request->header('X-Device-Id'))) {
            $token->delete();

            throw new AuthenticationException('This session is bound to another device.');
        }

        return $next($request);
    }
}
