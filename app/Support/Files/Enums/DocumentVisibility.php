<?php

namespace App\Support\Files\Enums;

/**
 * Whether a document is reachable by URL alone. `Private` is the default for
 * everything the platform sells or that belongs to one person; `Public` is only
 * for assets a logged-out visitor must be able to see (branding, landing images,
 * package covers, video posters).
 */
enum DocumentVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    /** The filesystem disk this visibility maps to. */
    public function disk(): string
    {
        return $this === self::Public
            ? (string) config('documents.public_disk', 'public')
            : (string) config('documents.private_disk', 'local');
    }

    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
