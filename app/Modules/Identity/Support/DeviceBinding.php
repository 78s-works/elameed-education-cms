<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Http\Middleware\EnsureTokenDevice;
use Laravel\Sanctum\NewAccessToken;

/**
 * Device-binding for Sanctum access tokens.
 *
 * The client sends a stable per-browser id in the `X-Device-Id` header. At mint
 * time we store its SHA-256 hash on the token row; on every later authenticated
 * request {@see EnsureTokenDevice} checks
 * that the header still hashes to the stored value. A token lifted out of one
 * browser's storage therefore fails to authenticate anywhere else.
 *
 * Only the hash is ever persisted, so a leaked DB row cannot reveal the id.
 */
final class DeviceBinding
{
    /** SHA-256 of the raw header, or null when no (usable) id was sent. */
    public static function hash(?string $rawDeviceId): ?string
    {
        $raw = is_string($rawDeviceId) ? trim($rawDeviceId) : '';

        return $raw === '' ? null : hash('sha256', $raw);
    }

    /**
     * Stamp a freshly-minted token with the device hash and return the plaintext
     * token string. When no device id is present the token is left unbound.
     */
    public static function bind(NewAccessToken $token, ?string $rawDeviceId): string
    {
        $hash = self::hash($rawDeviceId);

        if ($hash !== null) {
            $token->accessToken->forceFill(['device_id' => $hash])->save();
        }

        return $token->plainTextToken;
    }
}
