<?php

return [

    // Swappable media backend (02_Architecture.md §7 — DRM-later via MediaProvider).
    'provider' => env('MEDIA_PROVIDER', 'local'),

    // Object-storage disk for source + HLS renditions (Media step wires S3/MinIO).
    'disk' => env('MEDIA_DISK', 'public'),

    // Playback token / signed-URL lifetime (seconds). Short so links can't be shared.
    'playback_ttl' => (int) env('MEDIA_PLAYBACK_TTL', 120),

    // Shared secret the transcode worker signs its callback with.
    'transcode_secret' => env('MEDIA_TRANSCODE_SECRET', 'local-transcode-secret'),

];
