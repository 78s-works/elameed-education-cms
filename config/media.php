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

    /*
    |--------------------------------------------------------------------------
    | Remote Media Host (OVH)
    |--------------------------------------------------------------------------
    | Only consulted when 'provider' === 'remote'. Every value is env-driven so
    | going live is a configuration change, not a code change. Secrets are never
    | persisted to the DB or written to logs; the playback signing key is read
    | from a file path, not the environment. See docs/MEDIA_HOST_API_v1.md.
    */
    'host' => [
        'base_url' => rtrim((string) env('MEDIA_HOST_BASE_URL', ''), '/'),
        'api_key' => env('MEDIA_HOST_API_KEY'),
        'api_secret' => env('MEDIA_HOST_API_SECRET'),

        // HMAC secret the Media Host signs its processing callbacks with.
        'callback_secret' => env('MEDIA_HOST_CALLBACK_SECRET'),

        // Asymmetric key pair for signing (private) / verifying (public) the
        // short-lived playback tokens handed to the Media Host / player.
        'playback_private_key_path' => env('MEDIA_HOST_PLAYBACK_PRIVATE_KEY_PATH'),
        'playback_public_key_path' => env('MEDIA_HOST_PLAYBACK_PUBLIC_KEY_PATH'),

        'connect_timeout' => (int) env('MEDIA_HOST_CONNECT_TIMEOUT', 10),
        'request_timeout' => (int) env('MEDIA_HOST_REQUEST_TIMEOUT', 30),
        'upload_session_ttl' => (int) env('MEDIA_HOST_UPLOAD_SESSION_TTL', 3600),
        'playback_token_ttl' => (int) env('MEDIA_HOST_PLAYBACK_TOKEN_TTL', 900),
        'max_upload_bytes' => env('MEDIA_HOST_MAX_UPLOAD_BYTES') !== null && env('MEDIA_HOST_MAX_UPLOAD_BYTES') !== ''
            ? (int) env('MEDIA_HOST_MAX_UPLOAD_BYTES')
            : null,
        'verify_ssl' => filter_var(env('MEDIA_HOST_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),

        // Contract version this client speaks (sent as X-Media-Api-Version).
        'api_version' => env('MEDIA_HOST_API_VERSION', 'v1'),
    ],

];
