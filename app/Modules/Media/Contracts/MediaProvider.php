<?php

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Models\MediaAsset;

/**
 * Abstraction over the video backend so business/authorization logic never
 * depends on the delivery mechanism (02_Architecture.md §7). The default local
 * provider stubs storage/transcode/keys; a real self-hosted (FFmpeg + nginx) or
 * managed (Bunny) provider drops in behind this interface.
 */
interface MediaProvider
{
    public function name(): string;

    /**
     * Signed, resumable upload target — the file goes straight to object storage,
     * bypassing the app servers.
     *
     * @return array{upload_url: string, method: string}
     */
    public function createUploadTarget(MediaAsset $asset): array;

    /** Short-lived signed HLS manifest URL bound to a playback token. */
    public function manifestUrl(MediaAsset $asset, string $token): string;

    /** The AES-128 decryption key, released only to an authorized student. */
    public function encryptionKey(MediaAsset $asset): string;
}
