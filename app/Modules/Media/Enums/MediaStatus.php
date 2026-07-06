<?php

namespace App\Modules\Media\Enums;

/**
 * Lifecycle of a media asset. Attachments (pdf/file/link) are `ready` on
 * creation; videos move uploading → transcoding → ready|failed (Media step).
 */
enum MediaStatus: string
{
    case Uploading = 'uploading';
    case Transcoding = 'transcoding';
    case Ready = 'ready';
    case Failed = 'failed';
}
