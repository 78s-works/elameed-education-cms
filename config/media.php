<?php

return [

    // Swappable media backend (02_Architecture.md §7 — DRM-later via MediaProvider).
    'provider' => env('MEDIA_PROVIDER', 'local'),

    // PRIVATE disk for source + encrypted HLS renditions. MUST NOT be the public
    // disk — the source MP4 and .ts segments must never be reachable via /storage;
    // all delivery goes through the token-gated stream/segment/key endpoints.
    'disk' => env('MEDIA_DISK', 'local'),

    // Playback token / signed-URL lifetime (seconds). Short so links can't be shared.
    'playback_ttl' => (int) env('MEDIA_PLAYBACK_TTL', 120),

    // Shared secret the transcode worker signs its callback with.
    'transcode_secret' => env('MEDIA_TRANSCODE_SECRET', 'local-transcode-secret'),

    // FFmpeg (02_Architecture.md §7.2). Absolute path or a binary on PATH. Real
    // AES-128 HLS + burned-in watermark requires this; without it, transcode fails
    // (no raw video is ever served as a fallback).
    'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
    'hls_time' => (int) env('MEDIA_HLS_TIME', 6),

    // Burned-in per-student watermark (§7.3 — the biggest deterrent against resale;
    // makes any leak traceable). This is a separate encrypted transcode per student.
    'watermark' => [
        'enabled' => (bool) env('MEDIA_WATERMARK', true),
        // TrueType font used by FFmpeg drawtext (Windows ships arial.ttf).
        'font' => env('MEDIA_WATERMARK_FONT', 'C:/Windows/Fonts/arial.ttf'),
        'fontsize' => (int) env('MEDIA_WATERMARK_FONTSIZE', 22),
        'opacity' => env('MEDIA_WATERMARK_OPACITY', '0.35'),
    ],

];
