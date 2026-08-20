<?php

namespace App\Support\Files\Enums;

/**
 * Lifecycle of the blob behind a document row. Almost every upload is `Ready`
 * the moment it is written; the other states exist for files that arrive through
 * an async path (a transcode source) or that a future scanner rejects.
 */
enum DocumentStatus: string
{
    case Ready = 'ready';
    case Processing = 'processing';
    case Failed = 'failed';
    case Quarantined = 'quarantined';

    /** May this document be served to anyone? */
    public function isServable(): bool
    {
        return $this === self::Ready;
    }
}
