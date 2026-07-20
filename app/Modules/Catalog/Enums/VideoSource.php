<?php

namespace App\Modules\Catalog\Enums;

/**
 * Which video source of a lesson students see. A lesson may store both a
 * protected upload (`video_asset_id`) and a YouTube link (`youtube_url`); this
 * toggle picks the one exposed for playback (docs/design/lesson-video-sources.md).
 */
enum VideoSource: string
{
    case Upload = 'upload';
    case Youtube = 'youtube';
}
