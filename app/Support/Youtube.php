<?php

namespace App\Support;

/**
 * Parsing/validation for YouTube lesson-video links
 * (docs/design/lesson-video-sources.md). Kept provider-neutral in App\Support so
 * both the Catalog authoring side and the Media playback side can use it without
 * a cross-module dependency.
 */
class Youtube
{
    /**
     * Extract the 11-char video id from the common YouTube URL forms, or null if
     * the string isn't a recognizable YouTube link:
     *   youtu.be/<id>, youtube.com/watch?v=<id>, /embed/<id>, /shorts/<id>, /live/<id>.
     */
    public static function videoId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $patterns = [
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
            '~[?&]v=([A-Za-z0-9_-]{11})~',
            '~youtube\.com/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** True when the URL resolves to a YouTube video id. */
    public static function isValid(?string $url): bool
    {
        return self::videoId($url) !== null;
    }

    /** The privacy-friendly embed URL for a resolved video id. */
    public static function embedUrl(string $videoId): string
    {
        return "https://www.youtube-nocookie.com/embed/{$videoId}";
    }
}
