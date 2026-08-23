<?php

namespace Tests\Unit\Files;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guard that keeps this refactor from unwinding.
 *
 * Files used to be written from eleven places, each with its own path scheme,
 * its own disk, and sometimes no database row at all. Fixing that once is easy;
 * keeping it fixed is not, because the next feature that needs to save a file
 * will reach for `Storage::` unless something stops it.
 *
 * So: nothing under app/Modules may touch a disk. Uploads go through
 * DocumentService. The media pipeline is the single carved-out exception — it
 * owns the encrypted HLS tree, which is a streaming artefact rather than a
 * stored document, and this change deliberately does not touch playback.
 */
class StorageIsOwnedByDocumentServiceTest extends TestCase
{
    /**
     * Ways of putting bytes on a disk. `->store(` is matched only when it is NOT
     * the service's own method — `$this->documents->store($file, ...)` is the
     * whole point, while `$file->store('some/path', 'public')` is the habit being
     * banned.
     */
    private const FORBIDDEN = [
        'Storage::' => '/Storage::/',
        '->store(' => '/(?<!documents)->store\(/',
        '->storeAs(' => '/->storeAs\(/',
        '->putFile(' => '/->putFile(As)?\(/',
    ];

    /** Files allowed to write to a disk directly, and why. */
    private const ALLOWED = [
        // Encrypted HLS renditions, segments and keys — the pipeline's own tree.
        'app/Modules/Media/Services/HlsTranscoder.php',
        // Reads a frame out of the source to make a poster; the poster itself is
        // stored through DocumentService.
        'app/Modules/Media/Services/MediaThumbnailService.php',
        // Streams segments and the key back to the player.
        'app/Modules/Media/Http/Controllers/PlaybackController.php',
        // Talks to the remote Media Host, not to our disks.
        'app/Modules/Media/Services/RemoteVideoService.php',
    ];

    #[Test]
    public function no_module_writes_to_a_disk_outside_the_document_service(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(base_path('app/Modules')) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $label => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = "{$relative} uses {$label}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files write to a disk directly. Use App\Support\Files\DocumentService',
            'so the file gets a documents row, a tenant-scoped path, and cleanup on delete.',
            'If a file genuinely belongs to the media pipeline, add it to self::ALLOWED',
            'with a comment saying why.',
            '',
            ...$offenders,
        ]));
    }

    /** @return array<int, string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
