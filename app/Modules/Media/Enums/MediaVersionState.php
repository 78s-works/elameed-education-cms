<?php

namespace App\Modules\Media\Enums;

/**
 * Lifecycle of a single video VERSION on the Media Host (see
 * docs/MEDIA_HOST_API_v1.md §5). The owning asset's servable version only
 * advances once a new version reaches `ready`, so replacement is atomic and never
 * interrupts playback of the current version.
 */
enum MediaVersionState: string
{
    case Pending = 'pending';         // record created, no upload yet
    case Uploading = 'uploading';     // upload intent issued, bytes in flight
    case Uploaded = 'uploaded';       // bytes received + verified by the host
    case Processing = 'processing';   // host is transcoding
    case Ready = 'ready';             // servable
    case Failed = 'failed';           // processing failed (retryable)
    case Replacing = 'replacing';     // superseded by a newer version being prepared
    case Quarantined = 'quarantined'; // access frozen pending restore/purge
    case Purged = 'purged';           // permanently removed on the host

    /** Allowed forward transitions (guards against out-of-order callbacks). */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Uploading, self::Uploaded, self::Quarantined, self::Failed],
            self::Uploading => [self::Uploaded, self::Failed, self::Quarantined],
            self::Uploaded => [self::Processing, self::Failed, self::Quarantined],
            self::Processing => [self::Ready, self::Failed, self::Quarantined],
            self::Ready => [self::Replacing, self::Quarantined],
            self::Failed => [self::Processing, self::Quarantined],           // retry
            self::Replacing => [self::Quarantined],
            self::Quarantined => [self::Ready, self::Purged],                // restore | purge
            self::Purged => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Purged;
    }

    public function isServable(): bool
    {
        return $this === self::Ready;
    }
}
