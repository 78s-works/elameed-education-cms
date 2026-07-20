<?php

namespace App\Modules\Media\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Media\Contracts\MediaProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Models\PlaybackSession;
use App\Support\Youtube;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The playback authorization gate — the highest-value endpoint (DoD §7.2). A
 * short-lived token is issued ONLY for an authorized, in-window enrollment (or a
 * free-preview / free course, or a teacher previewing their own content). The AES
 * key is released only after access is RE-checked at key time, so a leaked token
 * stops working the moment access lapses. Tokens are stored hashed and expire
 * quickly, so links aren't shareable.
 *
 * Content is served as AES-128-encrypted HLS with a per-student burned-in
 * watermark (§7.3): each viewer gets their own encrypted rendition, transcoded on
 * first play. The raw source is never served.
 */
class PlaybackService
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly MediaProvider $provider,
        private readonly HlsTranscoder $transcoder,
    ) {}

    /**
     * Student playback: authorize, ensure the student's encrypted rendition, issue token.
     *
     * @return array{token: string, manifest_url: string, key_url: string, expires_at: string}
     */
    public function issue(int $tenantId, User $user, Lesson $lesson, ?string $fingerprint, ?string $ip): array
    {
        $this->assertAccess($tenantId, $user, $lesson);
        $asset = $this->readyVideo($lesson);
        $this->transcoder->ensureRendition($asset, $user, $this->watermark($user));

        return $this->openSession($tenantId, $user, $asset, $lesson, 'student', $fingerprint, $ip);
    }

    /**
     * YouTube-sourced lesson playback. Runs the SAME access gate as the encrypted
     * path (enrollment or free-preview), but returns an embed URL — there is no
     * token, no key, and no watermark. The YouTube tier is unprotected: once the
     * URL is released it is a normal, shareable link, so teachers should use
     * unlisted videos (docs/design/lesson-video-sources.md §6).
     *
     * @return array{source: string, video_id: string, embed_url: string}
     */
    public function issueYoutube(int $tenantId, ?User $user, Lesson $lesson): array
    {
        $this->assertAccess($tenantId, $user, $lesson);

        $videoId = Youtube::videoId($lesson->youtube_url);
        if ($videoId === null) {
            throw new ConflictHttpException('This lesson has no YouTube video.');
        }

        return [
            'source' => 'youtube',
            'video_id' => $videoId,
            'embed_url' => Youtube::embedUrl($videoId),
        ];
    }

    /**
     * Teacher self-preview of their own asset (no enrollment). Watermarked with
     * the teacher's own name so preview copies are traceable too.
     *
     * @return array{token: string, manifest_url: string, key_url: string, expires_at: string}
     */
    public function issuePreview(int $tenantId, User $teacher, MediaAsset $asset, ?string $ip = null): array
    {
        $this->assertStaff($tenantId, $teacher);

        if ($asset->status !== MediaStatus::Ready && ! $asset->source_key) {
            throw new ConflictHttpException('This media has no source to preview.');
        }

        $this->transcoder->ensureRendition($asset, $teacher, $this->watermark($teacher, 'preview'));

        return $this->openSession($tenantId, $teacher, $asset, $asset->lesson_id ? Lesson::withoutGlobalScopes()->find($asset->lesson_id) : null, 'preview', null, $ip);
    }

    /** Returns the raw AES key after re-validating the token AND access. */
    public function resolveKey(string $token): string
    {
        $session = $this->validSession($token);
        $this->reassertAccess($session);

        $rendition = $this->readyRendition($session);

        return base64_decode($rendition->enc_key, true);
    }

    /** Valid-token gate for stream/segment delivery (content is encrypted; key is the real gate). */
    public function renditionForToken(string $token): MediaRendition
    {
        return $this->readyRendition($this->validSession($token));
    }

    /** nginx auth_request check — is this token currently valid? */
    public function authorizeToken(string $token): bool
    {
        return $this->lookup($token) !== null;
    }

    private function openSession(int $tenantId, User $user, MediaAsset $asset, ?Lesson $lesson, string $scope, ?string $fingerprint, ?string $ip): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addSeconds((int) config('media.playback_ttl', 120));

        $session = new PlaybackSession([
            'user_id' => $user->getKey(),
            'lesson_id' => $lesson?->getKey(),
            'media_asset_id' => $asset->getKey(),
            'scope' => $scope,
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

    /** Re-run the appropriate access gate for a session at key-release time. */
    private function reassertAccess(PlaybackSession $session): void
    {
        $user = User::find($session->user_id);

        if ($session->scope === 'preview') {
            $this->assertStaff((int) $session->tenant_id, $user);

            return;
        }

        $lesson = $session->lesson_id ? Lesson::withoutGlobalScopes()->find($session->lesson_id) : null;
        if ($lesson === null) {
            throw new AccessDeniedHttpException('Playback not authorized.');
        }
        $this->assertAccess((int) $session->tenant_id, $user, $lesson);
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

    /** The user must be an active teacher/assistant of the tenant (owns the content). */
    private function assertStaff(int $tenantId, ?User $user): void
    {
        $ok = $user !== null && TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->whereIn('role', [TenantUserRole::Teacher->value, TenantUserRole::Assistant->value])
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        if (! $ok) {
            throw new AccessDeniedHttpException('Not authorized to preview this media.');
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

    private function readyRendition(PlaybackSession $session): MediaRendition
    {
        $rendition = MediaRendition::withoutGlobalScopes()
            ->where('media_asset_id', $session->media_asset_id)
            ->where('user_id', $session->user_id)
            ->first();

        if ($rendition === null || ! $rendition->isReady()) {
            throw new ConflictHttpException('The encrypted rendition is not ready.');
        }

        return $rendition;
    }

    private function watermark(User $user, ?string $suffix = null): string
    {
        $parts = array_filter([$user->name, $user->phone, $suffix]);

        return implode('  ·  ', $parts);
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
