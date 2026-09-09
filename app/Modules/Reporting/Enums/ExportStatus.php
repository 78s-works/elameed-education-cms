<?php

namespace App\Modules\Reporting\Enums;

/**
 * Lifecycle of one requested export.
 *
 * `Expired` is deliberately distinct from a deleted row: the request stays
 * visible in the list, saying the file is gone rather than pretending the export
 * never happened, so a teacher looking for last week's ledger sees why the link
 * no longer works.
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Expired], true);
    }
}
