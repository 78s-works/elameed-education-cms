<?php

namespace App\Modules\Media\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failure talking to the Media Host, carrying the host's error `code`
 * (docs/MEDIA_HOST_API_v1.md §8) and the HTTP status so callers can map it to
 * the app's error envelope and decide retry behaviour. Never carries secrets.
 */
class MediaHostException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'The remote Media Host is not configured.', null, false);
    }
}
