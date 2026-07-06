<?php

namespace App\Modules\Media\Enums;

/**
 * Kind of media asset (03_Data_Model.md §3). `hls_video` is produced by the
 * self-hosted pipeline (Media step); `pdf|file|link` are lesson attachments.
 */
enum MediaType: string
{
    case HlsVideo = 'hls_video';
    case Pdf = 'pdf';
    case File = 'file';
    case Link = 'link';
}
