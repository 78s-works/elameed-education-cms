<?php

namespace App\Modules\Media\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackSession;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The playback authorization gate — the highest-value endpoint (DoD §7.2). A
 * short-lived token is issued ONLY for an authorized, in-window enrollment (or a
 * free-preview / free course). The AES key is released only after the enrollment
 * is RE-checked at key time, so a leaked token stops working the moment access
 * lapses. Tokens are stored hashed and expire quickly, so links aren't shareable.
 */
class PlaybackService
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly MediaProvider $provider,
    ) {}

    /**
     * @return array{token: string, manifest_url: string, key_url: string, expires_at: string}
     */
    public function issue(int $tenantId, User $user, Lesson $lesson, ?string $fingerprint, ?string $ip): array
    {
        $this->assertAccess($tenantId, $user, $lesson);
        $asset = $this->readyVideo($lesson);

        $token = Str::random(64);
        $expiresAt = now()->addSeconds((int) config('media.playback_ttl', 120));

        $session = new PlaybackSession([
            'user_id' => $user->getKey(),
            'lesson_id' => $lesson->getKey(),
            'media_asset_id' => $asset->getKey(),
            'token_hash' => $this->hash($token),
            'device_fingerprint' => $fingerprint,
            'ip' => $ip,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);
        $session->tenant_id = $tenantId;
        $session->save();

        return [
            'token' => $token,
            'manifest_url' => $this->provider->manifestUrl($asset, $token),
            'key_url' => url("/api/v1/media/key/{$token}"),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** Returns the AES key after re-validating the token AND the enrollment. */
    public function resolveKey(string $token): string
    {
        $session = $this->validSession($token);
        $lesson = Lesson::withoutGlobalScopes()->find($session->lesson_id);

        if ($lesson === null) {
            throw new AccessDeniedHttpException('Playback not authorized.');
        }

        $user = User::find($session->user_id);
        $this->assertAccess((int) $session->tenant_id, $user, $lesson);

        $asset = MediaAsset::withoutGlobalScopes()->find($session->media_asset_id);

        return $this->provider->encryptionKey($asset);
    }

    /** Returns the asset for a valid token after re-checking access (dev streaming). */
    public function assetForToken(string $token): MediaAsset
    {
        $session = $this->validSession($token);
        $lesson = Lesson::withoutGlobalScopes()->find($session->lesson_id);

        if ($lesson === null) {
            throw new AccessDeniedHttpException('Playback not authorized.');
        }

        $this->assertAccess((int) $session->tenant_id, User::find($session->user_id), $lesson);

        return MediaAsset::withoutGlobalScopes()->find($session->media_asset_id);
    }

    /** nginx auth_request check — is this token currently valid? */
    public function authorizeToken(string $token): bool
    {
        return $this->lookup($token) !== null;
    }

    private function assertAccess(int $tenantId, ?User $user, Lesson $lesson): void
    {
        if ($lesson->is_free_preview) {
            return;
        }

        $course = $lesson->course;

        if ($user === null || $course === null
            || ! $this->enrollments->hasAccess($tenantId, $user->getKey(), $course)) {
            throw new AccessDeniedHttpException('You do not have access to this lesson.');
        }
    }

    private function readyVideo(Lesson $lesson): MediaAsset
    {
        $asset = $lesson->video_asset_id
            ? MediaAsset::withoutGlobalScopes()->find($lesson->video_asset_id)
            : null;

        if ($asset === null || $asset->status !== MediaStatus::Ready) {
            throw new ConflictHttpException('This lesson has no ready video.');
        }

        return $asset;
    }

    private function validSession(string $token): PlaybackSession
    {
        $session = $this->lookup($token);

        if ($session === null) {
            throw new AccessDeniedHttpException('Playback token is invalid or expired.');
        }

        return $session;
    }

    private function lookup(string $token): ?PlaybackSession
    {
        return PlaybackSession::withoutGlobalScopes()
            ->where('token_hash', $this->hash($token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->first();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
