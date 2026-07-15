<?php

namespace App\Modules\Media\Support;

use Illuminate\Support\Str;

/**
 * Mints (and, for tests/diagnostics, verifies) the short-lived JWT the Media Host
 * uses to authorize playback (docs/MEDIA_HOST_API_v1.md §7). Uses RS256 with the
 * configured private key in production; falls back to HS256 (symmetric, derived
 * from the callback secret / app key) when no key file is present, so tests and
 * dev run without generating a key pair. Tokens carry tenant/user/video/version/
 * session binding and a short TTL, and are never logged.
 */
class PlaybackTokenIssuer
{
    /**
     * @return array{token:string, jti:string, expires_at:string, alg:string}
     */
    public function issue(array $claims, ?int $ttl = null): array
    {
        $ttl ??= (int) config('media.host.playback_token_ttl', 900);
        $now = time();
        $jti = (string) Str::uuid();

        $payload = array_merge([
            'iss' => 'elameed-api',
            'aud' => 'media-host',
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
        ], $claims);

        [$alg, $token] = $this->encode($payload);

        return [
            'token' => $token,
            'jti' => $jti,
            'expires_at' => date(DATE_ATOM, $now + $ttl),
            'alg' => $alg,
        ];
    }

    /** Verify a token and return its claims, or null if invalid/expired. */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode($this->b64decode($h), true);
        $payload = json_decode($this->b64decode($p), true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        $signingInput = "{$h}.{$p}";
        $signature = $this->b64decode($s);

        $valid = ($header['alg'] ?? '') === 'RS256'
            ? $this->verifyRs256($signingInput, $signature)
            : hash_equals($this->hs256($signingInput), $signature);

        if (! $valid) {
            return null;
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /** @return array{0:string,1:string} [alg, token] */
    private function encode(array $payload): array
    {
        $privatePath = config('media.host.playback_private_key_path');

        if ($privatePath && is_readable($privatePath) && ($key = openssl_pkey_get_private((string) file_get_contents($privatePath)))) {
            $alg = 'RS256';
            $segments = [$this->b64(json_encode(['alg' => $alg, 'typ' => 'JWT'])), $this->b64(json_encode($payload))];
            $signature = '';
            openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256);
            $segments[] = $this->b64($signature);

            return [$alg, implode('.', $segments)];
        }

        // Symmetric dev/test fallback — production sets the RS256 key path.
        $alg = 'HS256';
        $segments = [$this->b64(json_encode(['alg' => $alg, 'typ' => 'JWT'])), $this->b64(json_encode($payload))];
        $segments[] = $this->b64($this->hs256(implode('.', $segments)));

        return [$alg, implode('.', $segments)];
    }

    private function hs256(string $input): string
    {
        $secret = (string) (config('media.host.callback_secret') ?: config('app.key'));

        return hash_hmac('sha256', $input, $secret, true);
    }

    private function verifyRs256(string $input, string $signature): bool
    {
        $publicPath = config('media.host.playback_public_key_path');
        if (! $publicPath || ! is_readable($publicPath)) {
            return false;
        }
        $key = openssl_pkey_get_public((string) file_get_contents($publicPath));

        return $key && openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
