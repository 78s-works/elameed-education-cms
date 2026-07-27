<?php

namespace App\Modules\Media\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A media-pipeline failure that maps to a clean API error envelope instead of a
 * raw 500. Thrown by the transcode/playback path when a video cannot be served
 * (source not ready, processing backend unavailable, transcode failed).
 *
 * Carries a stable machine `errorCode` (for the frontend to localize) and the
 * HTTP status to return. Messages are kept user-safe — no FFmpeg output, paths,
 * or stack traces leak through — and translatable via __() (Arabic-first UI).
 *
 * Mapped in App\Support\Http\ApiExceptionRenderer.
 */
class MediaException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The asset is marked ready but its source file is missing / not yet transcodable → 409. */
    public static function sourceMissing(): self
    {
        return new self('media_not_ready', __('This video is not ready to play yet. Please try again later.'), 409);
    }

    /** The transcode backend (FFmpeg) is not configured/available → 503. */
    public static function processingUnavailable(): self
    {
        return new self('media_processing_unavailable', __('Video playback is temporarily unavailable. Please try again later.'), 503);
    }

    /** The transcode ran but failed (bad/corrupt source, codec, etc.) → 422. Original cause chained, never surfaced. */
    public static function processingFailed(?Throwable $previous = null): self
    {
        return new self('media_processing_failed', __('This video could not be processed for playback.'), 422, $previous);
    }
}
