<?php

namespace App\Modules\Media\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Media\Contracts\MediaHostProvider;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVersionState;
use App\Modules\Media\Jobs\StartRemoteProcessingJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaUploadSession;
use App\Modules\Media\Models\MediaVersion;
use App\Modules\Media\Models\PlaybackSession;
use App\Modules\Media\Support\PlaybackTokenIssuer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Orchestrates the remote (OVH Media Host) video lifecycle: upload intents,
 * completion, processing, signed-callback application with a guarded state
 * machine, atomic version replacement, quarantine/restore/purge, and short-lived
 * playback authorization. Reuses EnrollmentService for access — no duplication of
 * course/lesson/tenant/authorization logic. See docs/MEDIA_HOST_API_v1.md.
 */
class RemoteVideoService
{
    public function __construct(
        private readonly MediaHostProvider $host,
        private readonly EnrollmentService $enrollments,
        private readonly PlaybackTokenIssuer $tokens,
    ) {}

    /** Remote must be explicitly enabled + configured — never silently fall back to local. */
    private function assertRemote(): void
    {
        if (config('media.provider') !== 'remote') {
            throw new ConflictHttpException('The remote media provider is not enabled (MEDIA_PROVIDER=remote).');
        }
        if (! $this->host->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'The Media Host is not configured.');
        }
    }

    // ── Uploads ──────────────────────────────────────────────────────────────

    /** New video (version 1) + authorized upload intent. Idempotent on $key. */
    public function createUploadIntent(int $tenantId, User $user, ?Lesson $lesson, array $data, string $key): array
    {
        $this->assertRemote();

        if ($reuse = $this->reuseSession($key)) {
            return $reuse;
        }

        return DB::transaction(function () use ($tenantId, $user, $lesson, $data, $key): array {
            $asset = new MediaAsset([
                'type' => MediaType::HlsVideo->value,
                'status' => MediaStatus::Uploading->value,
                'provider' => 'remote',
                'title' => $data['title'] ?? $data['filename'],
                'lesson_id' => $lesson?->getKey(),
            ]);
            $asset->tenant_id = $tenantId;
            $asset->save();

            // Link the lesson to its video (mirrors the local flow); playback
            // resolves the asset via lessons.video_asset_id.
            if ($lesson !== null) {
                Lesson::query()->whereKey($lesson->getKey())->update(['video_asset_id' => $asset->getKey()]);
            }

            return $this->startIntent($tenantId, $user, $asset, $data, $key);
        });
    }

    /** New version of an existing remote video (the current version keeps serving). */
    public function replaceUpload(int $tenantId, User $user, MediaAsset $asset, array $data, string $key): array
    {
        $this->assertRemote();
        if (! $asset->isRemote()) {
            throw new ConflictHttpException('Only remote videos can be replaced through the Media Host.');
        }
        if ($reuse = $this->reuseSession($key)) {
            return $reuse;
        }

        return DB::transaction(fn (): array => $this->startIntent($tenantId, $user, $asset, $data, $key));
    }

    private function startIntent(int $tenantId, User $user, MediaAsset $asset, array $data, string $key): array
    {
        $version = $this->newVersion($asset, $tenantId);

        $resp = $this->host->createUpload([
            'tenant_ref' => "t_{$tenantId}",
            'video_ref' => $asset->uuid,
            'version' => $version->version,
            'filename' => $data['filename'],
            'size_bytes' => (int) $data['size_bytes'],
            'content_type' => $data['content_type'],
            'checksum_sha256' => $data['checksum_sha256'] ?? null,
        ], $key);

        $session = new MediaUploadSession([
            'media_version_id' => $version->getKey(),
            'created_by' => $user->getKey(),
            'idempotency_key' => $key,
            'host_upload_id' => $resp['upload_id'] ?? null,
            'upload_url' => $resp['upload_url'] ?? null,
            'protocol' => $resp['protocol'] ?? 'tus',
            'size_bytes' => (int) $data['size_bytes'],
            'max_bytes' => $resp['max_bytes'] ?? null,
            'content_type' => $data['content_type'],
            'checksum_sha256' => $data['checksum_sha256'] ?? null,
            'state' => 'created',
            'expires_at' => isset($resp['expires_at'])
                ? Carbon::parse($resp['expires_at'])
                : now()->addSeconds((int) config('media.host.upload_session_ttl', 3600)),
        ]);
        $session->tenant_id = $tenantId;
        $session->save();

        $this->transition($version, MediaVersionState::Uploading);

        return $this->intentPayload($asset, $version->fresh(), $session->fresh());
    }

    /** Client reports the direct upload finished → verify with host, then process. */
    public function completeUpload(MediaUploadSession $session): array
    {
        $this->assertRemote();

        $this->host->completeUpload((string) $session->host_upload_id);
        $session->update(['state' => 'uploaded']);

        $version = MediaVersion::withoutGlobalScopes()->findOrFail($session->media_version_id);
        $this->transition($version, MediaVersionState::Uploaded);

        StartRemoteProcessingJob::dispatch($version->getKey(), 1);

        return ['upload_id' => $session->host_upload_id, 'state' => 'uploaded', 'video' => $version->asset->uuid];
    }

    /** Called by the job: begin async processing on the host. */
    public function startProcessing(MediaVersion $version, int $attempt): void
    {
        $this->assertRemote();

        $session = $version->uploadSessions()->orderByDesc('id')->first();
        $resp = $this->host->startProcessing(
            (string) $session?->host_upload_id,
            ['profiles' => ['hls-720p', 'hls-1080p']],
            "proc-{$version->getKey()}-{$attempt}",
        );

        $version->host_video_id = $resp['host_video_id'] ?? $version->host_video_id;
        $version->meta = array_merge((array) $version->meta, ['proc_attempt' => $attempt]);
        $version->save();

        $this->transition($version, MediaVersionState::Processing);
    }

    /** Deliberate retry after a failed transcode → a NEW processing attempt. */
    public function retryProcessing(MediaVersion $version): void
    {
        $this->assertRemote();
        if ($version->state !== MediaVersionState::Failed) {
            throw new ConflictHttpException('Only a failed version can be retried.');
        }
        $attempt = (int) (($version->meta['proc_attempt'] ?? 1)) + 1;
        $this->transition($version, MediaVersionState::Processing);
        StartRemoteProcessingJob::dispatch($version->getKey(), $attempt);
    }

    // ── Callback (state machine) ───────────────────────────────────────────────

    /** Apply a validated, de-duplicated processing callback. */
    public function applyCallback(array $payload): MediaVersion
    {
        $asset = MediaAsset::withoutGlobalScopes()->where('uuid', $payload['video_ref'] ?? '')->first();
        if (! $asset) {
            throw new NotFoundHttpException('Unknown video reference.');
        }
        $version = MediaVersion::withoutGlobalScopes()
            ->where('media_asset_id', $asset->getKey())
            ->where('version', (int) ($payload['version'] ?? 0))
            ->first();
        if (! $version) {
            throw new NotFoundHttpException('Unknown video version.');
        }

        if (! empty($payload['host_video_id'])) {
            $version->host_video_id = $payload['host_video_id'];
        }

        $state = match ($payload['state'] ?? '') {
            'ready' => MediaVersionState::Ready,
            'failed' => MediaVersionState::Failed,
            default => throw new ConflictHttpException('Unsupported callback state.'),
        };

        if ($state === MediaVersionState::Ready) {
            $version->playback_id = $payload['playback_id'] ?? $version->playback_id;
            $version->thumbnail_url = $payload['thumbnail_url'] ?? $version->thumbnail_url;
            $version->duration_sec = $payload['duration_sec'] ?? $version->duration_sec;
            $version->ready_at = now();
            $this->transition($version, MediaVersionState::Ready);   // guarded + saves
            $this->makeCurrent($asset, $version);
        } else {
            $version->error = $payload['error']['message'] ?? 'Processing failed.';
            $this->transition($version, MediaVersionState::Failed);
        }

        return $version->fresh();
    }

    // ── Quarantine / restore / purge ──────────────────────────────────────────

    public function quarantine(MediaVersion $version): void
    {
        $this->assertRemote();
        $this->host->quarantine((string) $version->host_video_id);
        $this->transition($version, MediaVersionState::Quarantined);
    }

    public function restore(MediaVersion $version): void
    {
        $this->assertRemote();
        $this->host->restore((string) $version->host_video_id);
        $this->transition($version, MediaVersionState::Ready);
    }

    public function purge(MediaVersion $version): void
    {
        $this->assertRemote();
        $this->host->purge((string) $version->host_video_id);
        $this->transition($version, MediaVersionState::Purged);   // only valid from Quarantined
    }

    // ── Playback ──────────────────────────────────────────────────────────────

    /** Short-lived playback authorization for an eligible student. */
    public function issuePlayback(int $tenantId, User $user, Lesson $lesson, ?string $ip = null, ?string $fingerprint = null): array
    {
        $this->assertRemote();

        if (! $lesson->is_free_preview) {
            $course = $lesson->course;
            if ($course === null || ! $this->enrollments->hasAccess($tenantId, $user->getKey(), $course)) {
                throw new AccessDeniedHttpException('You do not have access to this lesson.');
            }
        }

        $asset = $lesson->video_asset_id ? MediaAsset::withoutGlobalScopes()->find($lesson->video_asset_id) : null;
        if ($asset === null || ! $asset->isRemote()) {
            throw new ConflictHttpException('This lesson has no remote video.');
        }
        $version = $asset->current_version_id ? MediaVersion::withoutGlobalScopes()->find($asset->current_version_id) : null;
        if ($version === null || ! $version->isReady()) {
            throw new ConflictHttpException('The video is not ready.');
        }

        $issued = $this->tokens->issue([
            'sub' => "u_{$user->getKey()}",
            'ten' => "t_{$tenantId}",
            'vid' => $version->host_video_id,
            'ver' => $version->version,
            'pb' => $version->playback_id,
        ]);

        $session = new PlaybackSession([
            'user_id' => $user->getKey(),
            'lesson_id' => $lesson->getKey(),
            'media_asset_id' => $asset->getKey(),
            'media_version_id' => $version->getKey(),
            'token_hash' => hash('sha256', $issued['jti']),
            'device_fingerprint' => $fingerprint,
            'ip' => $ip,
            'issued_at' => now(),
            'expires_at' => Carbon::parse($issued['expires_at']),
        ]);
        $session->tenant_id = $tenantId;
        $session->scope = 'remote';
        $session->save();

        // Re-stamp the token with the concrete session id (tenant/user/video/version/session binding).
        $issued = $this->tokens->issue([
            'sub' => "u_{$user->getKey()}",
            'ten' => "t_{$tenantId}",
            'vid' => $version->host_video_id,
            'ver' => $version->version,
            'pb' => $version->playback_id,
            'sid' => (string) $session->getKey(),
        ]);

        $base = rtrim((string) config('media.host.base_url'), '/');
        $apiV = (string) config('media.host.api_version', 'v1');

        return [
            'status' => 'ready',
            'playback_url' => "{$base}/{$apiV}/playback/{$version->playback_id}/index.m3u8?token={$issued['token']}",
            'thumbnail_url' => $version->thumbnail_url,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'session' => (string) $session->getKey(),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function reuseSession(string $key): ?array
    {
        $session = MediaUploadSession::withoutGlobalScopes()->where('idempotency_key', $key)->first();
        if ($session === null) {
            return null;
        }
        $version = MediaVersion::withoutGlobalScopes()->find($session->media_version_id);

        return $this->intentPayload($version->asset, $version, $session);
    }

    private function newVersion(MediaAsset $asset, int $tenantId): MediaVersion
    {
        $next = (int) MediaVersion::withoutGlobalScopes()->where('media_asset_id', $asset->getKey())->max('version') + 1;

        $version = new MediaVersion([
            'media_asset_id' => $asset->getKey(),
            'version' => $next,
            'provider' => 'remote',
            'state' => MediaVersionState::Pending->value,
        ]);
        $version->tenant_id = $tenantId;
        $version->save();

        return $version;
    }

    /** Guarded state transition — rejects out-of-order/invalid changes. */
    private function transition(MediaVersion $version, MediaVersionState $to): void
    {
        $from = $version->state;
        if ($from === $to) {
            $version->save();   // idempotent no-op (persists any side fields set by caller)

            return;
        }
        if (! $from->canTransitionTo($to)) {
            throw new ConflictHttpException("Invalid state transition: {$from->value} → {$to->value}.");
        }
        $version->state = $to;
        $version->save();
    }

    /** Atomically promote a ready version to be the asset's current one. */
    private function makeCurrent(MediaAsset $asset, MediaVersion $version): void
    {
        DB::transaction(function () use ($asset, $version): void {
            $previousId = $asset->current_version_id;
            $asset->forceFill([
                'current_version_id' => $version->getKey(),
                'thumbnail_url' => $version->thumbnail_url,
                'status' => MediaStatus::Ready->value,
            ])->save();

            if ($previousId && (int) $previousId !== (int) $version->getKey()) {
                $previous = MediaVersion::withoutGlobalScopes()->find($previousId);
                if ($previous && $previous->state === MediaVersionState::Ready) {
                    $previous->state = MediaVersionState::Replacing;
                    $previous->save();
                }
            }
        });
    }

    private function intentPayload(MediaAsset $asset, MediaVersion $version, MediaUploadSession $session): array
    {
        return [
            'video' => $asset->uuid,
            'version' => $version->version,
            'state' => $version->state->value,
            'upload' => [
                'upload_session' => (string) $session->getKey(),
                'upload_id' => $session->host_upload_id,
                'protocol' => $session->protocol,
                'upload_url' => $session->upload_url,
                'max_bytes' => $session->max_bytes,
                'expires_at' => $session->expires_at?->toIso8601String(),
            ],
        ];
    }
}
