<?php

namespace App\Support\Files\Enums;

/**
 * Coarse file family, derived from the extension at upload time. Purely a
 * presentation concern — it drives the icon, the preview mode, and the kind
 * filter chips in the files tabs. Access control keys off DocumentPurpose, not
 * off this.
 */
enum DocumentKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Document = 'document';
    case Archive = 'archive';
    case Other = 'other';

    public static function fromExtension(?string $extension): self
    {
        return match (strtolower((string) $extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp' => self::Image,
            'mp4', 'mov', 'webm', 'mkv', 'avi' => self::Video,
            'mp3', 'm4a', 'ogg', 'wav' => self::Audio,
            'pdf' => self::Pdf,
            'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt' => self::Document,
            'zip', 'rar', '7z', 'tar', 'gz' => self::Archive,
            default => self::Other,
        };
    }

    /** Can the browser render this inline, or is it download-only? */
    public function isPreviewable(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Audio, self::Pdf], true);
    }
}
