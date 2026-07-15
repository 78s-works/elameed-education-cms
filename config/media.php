<?php

return [

    // Swappable media backend (02_Architecture.md §7 — DRM-later via MediaProvider):
    //   local  → dev stub: upload lands on the media disk via a signed app route,
    //            segments are proxied through the token-gated stream endpoints.
    //   remote → production: presigned upload straight to the external store, and
    //            segments delivered DIRECTLY from the store via presigned URLs
    //            (the app never proxies video bytes).
    'provider' => env('MEDIA_PROVIDER', 'local'),

    // PRIVATE store for source + encrypted HLS. In production this points at the
    // external S3-compatible `media` disk; the source MP4 and .ts segments must
    // never be publicly reachable — delivery is via short-lived presigned URLs.
    // Dev/tests use `media_local` (or `local`).
    'disk' => env('MEDIA_DISK', 'local'),

    // Playback token lifetime (seconds) — the app-side session token. Short so a
    // copied token stops working quickly.
    'playback_ttl' => (int) env('MEDIA_PLAYBACK_TTL', 120),

    // Presigned segment/manifest URL lifetime (seconds) for direct-from-store
    // delivery. Kept at/above one segment duration but short enough that a copied
    // segment URL expires fast. Never longer than the playback token is useful.
    'stream_ttl' => (int) env('MEDIA_STREAM_TTL', 90),

    // Presigned upload-target lifetime (seconds) for direct-to-store uploads.
    'upload_ttl' => (int) env('MEDIA_UPLOAD_TTL', 3600),

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
