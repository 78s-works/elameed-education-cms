<?php

namespace App\Modules\Media\Contracts;

/**
 * Control-plane operations against the remote Media Host (OVH). Implemented by
 * RemoteMediaProvider over HTTP. Kept separate from the legacy local-delivery
 * MediaProvider so the existing local flow is untouched. All methods throw
 * App\Modules\Media\Exceptions\MediaHostException on failure — there is NO silent
 * fallback to local (that could store videos on the wrong server).
 *
 * See docs/MEDIA_HOST_API_v1.md for the wire contract.
 */
interface MediaHostProvider
{
    public function name(): string;

    /** True only when a base URL + credentials are configured. */
    public function isConfigured(): bool;

    /** GET /v1/health — readiness probe (no secrets in the result). */
    public function health(): array;

    /**
     * POST /v1/uploads — authorized, resumable upload intent.
     *
     * @param  array{tenant_ref:string,video_ref:string,version:int,filename:string,size_bytes:int,content_type:string,checksum_sha256?:string}  $payload
     * @return array{upload_id:string,protocol:string,upload_url:string,max_bytes:?int,expires_at:?string,headers?:array}
     */
    public function createUpload(array $payload, string $idempotencyKey): array;

    /** POST /v1/uploads/{id}/complete — verify received bytes. */
    public function completeUpload(string $uploadId): array;

    /** POST /v1/uploads/{id}/process — begin async transcoding. */
    public function startProcessing(string $uploadId, array $options, string $idempotencyKey): array;

    public function quarantine(string $hostVideoId): array;

    public function restore(string $hostVideoId): array;

    /** DELETE /v1/videos/{id} — permanent purge. */
    public function purge(string $hostVideoId): array;
}
