<?php

namespace App\Modules\Media\Services;

use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
use App\Modules\Media\Support\MediaPaths;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Produces a per-student, watermark-burned, AES-128-encrypted HLS transcode of a
 * source video (02_Architecture.md §7.2–7.3). Runs FFmpeg on the PRIVATE media
 * disk; the source and the encrypted segments are never web-served — only the
 * token-gated stream/segment/key endpoints reach them.
 *
 * The watermark (student name + phone) is drawn into the pixels and repositioned
 * over time, so a leaked recording is traceable and can't be cropped out cheaply.
 */
class HlsTranscoder
{
    private string $disk;

    public function __construct()
    {
        $this->disk = (string) config('media.disk', 'local');
    }

    public function available(): bool
    {
        return $this->ffmpeg() !== '';
    }

    /**
     * Return a ready rendition for (asset, viewer), transcoding on first use.
     * `$watermark` is the burned-in caption (e.g. "Ahmed Ali · 01000000001").
     */
    public function ensureRendition(MediaAsset $asset, User $viewer, string $watermark): MediaRendition
    {
        $rendition = MediaRendition::withoutGlobalScopes()
            ->where('media_asset_id', $asset->getKey())
            ->where('user_id', $viewer->getKey())
            ->first();

        if ($rendition && $rendition->isReady() && Storage::disk($this->disk)->exists($rendition->hls_dir.'/index.m3u8')) {
            return $rendition;
        }

        if (! $this->available()) {
            throw new RuntimeException('FFmpeg is not configured; cannot produce encrypted HLS.');
        }
        if (! $asset->source_key || ! Storage::disk($this->disk)->exists($asset->source_key)) {
            throw new RuntimeException('Source file for this media is missing.');
        }

        $key = random_bytes(16);
        $iv = bin2hex(random_bytes(16));
        $dir = MediaPaths::hlsDir($asset, $viewer->getKey());

        $rendition ??= new MediaRendition;
        $rendition->tenant_id = $asset->tenant_id;
        $rendition->media_asset_id = $asset->getKey();
        $rendition->user_id = $viewer->getKey();
        $rendition->fill(['status' => 'transcoding', 'hls_dir' => $dir, 'enc_key' => base64_encode($key), 'iv' => $iv, 'error' => null]);
        $rendition->save();

        try {
            $segments = $this->transcode($asset->source_key, $dir, $key, $iv, $watermark);
            $rendition->fill(['status' => 'ready', 'segment_count' => $segments])->save();
        } catch (\Throwable $e) {
            $rendition->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }

        return $rendition;
    }

    /** @return int segment count */
    private function transcode(string $sourceKey, string $dir, string $key, string $iv, string $watermark): int
    {
        $disk = Storage::disk($this->disk);
        $norm = static fn (string $p): string => str_replace('\\', '/', $p);

        // FFmpeg only speaks real local files, so everything happens in a LOCAL
        // scratch dir; only the encrypted playlist + segments are then uploaded to
        // the media store. FFmpeg RUNS from $work, so the drawtext filter and
        // key_info reference font/text/key by bare relative names — dodging the
        // Windows drive-letter colon its filtergraph parser can't escape.
        $work = storage_path('app/media-scratch/'.bin2hex(random_bytes(8)));
        $out = $work.'/out';
        @mkdir($out, 0775, true);

        try {
            $source = $norm($this->localSource($disk, $sourceKey, $work));

            copy((string) config('media.watermark.font'), $work.'/font.ttf');
            file_put_contents($work.'/wm.txt', $watermark);
            file_put_contents($work.'/enc.key', $key); // FFmpeg reads the raw key to encrypt
            // key_info: line1 = URI placeholder (rewritten per-request at serve time),
            // line2 = key file (relative to cwd), line3 = IV hex. enc.key is NEVER
            // uploaded to the store — the key lives only in the DB (encrypted).
            file_put_contents($work.'/keyinfo', "__KEYURI__\nenc.key\n".$iv."\n");

            $result = Process::path($work)->timeout(600)->run([
                $this->ffmpeg(),
                '-y',
                '-i', $source,
                '-vf', $this->watermarkFilter(),
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '128k',
                '-hls_time', (string) config('media.hls_time', 6),
                '-hls_playlist_type', 'vod',
                '-hls_key_info_file', 'keyinfo',
                '-hls_segment_filename', $norm($out).'/seg_%03d.ts',
                $norm($out).'/index.m3u8',
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('FFmpeg failed: '.mb_substr($result->errorOutput() ?: $result->output(), -1500));
            }

            return $this->uploadOutput($disk, $out, $dir);
        } finally {
            $this->rmrf($work); // scratch + key material never linger on disk
        }
    }

    /**
     * A real local path to the source. Local disks expose one directly; a remote
     * (S3) disk has none, so the source is streamed down to scratch first.
     */
    private function localSource(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $sourceKey, string $work): string
    {
        try {
            $path = $disk->path($sourceKey);
            if (is_file($path)) {
                return $path;
            }
        } catch (\Throwable) {
            // Remote disk — no local path; fall through to streaming download.
        }

        $local = $work.'/source.mp4';
        $stream = $disk->readStream($sourceKey);
        if ($stream === null) {
            throw new RuntimeException('Source file for this media is missing.');
        }
        $dest = fopen($local, 'wb');
        stream_copy_to_stream($stream, $dest);
        fclose($dest);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $local;
    }

    /**
     * Stream the FFmpeg output (index.m3u8 + seg_*.ts) up to the media store.
     *
     * @return int segment count
     */
    private function uploadOutput(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $outLocalDir, string $destDir): int
    {
        $segments = 0;

        foreach (glob($outLocalDir.'/*') ?: [] as $file) {
            $name = basename($file);
            $stream = fopen($file, 'rb');
            $disk->writeStream($destDir.'/'.$name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (str_starts_with($name, 'seg_')) {
                $segments++;
            }
        }

        if ($segments === 0 || ! $disk->exists($destDir.'/index.m3u8')) {
            throw new RuntimeException('Transcode produced no playable output.');
        }

        return $segments;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** drawtext caption: semi-transparent, boxed, jumping between corners over time. */
    private function watermarkFilter(): string
    {
        $size = (int) config('media.watermark.fontsize', 22);
        $op = config('media.watermark.opacity', '0.35');

        // font.ttf / wm.txt are relative to the process cwd (see transcode()).
        return 'drawtext=fontfile=font.ttf:textfile=wm.txt:reload=0'
            .":fontcolor=white@{$op}:fontsize={$size}:box=1:boxcolor=black@0.25:boxborderw=6"
            .':x=if(lt(mod(t\,20)\,10)\,w*0.05\,w*0.5)'
            .':y=if(lt(mod(t\,12)\,6)\,h*0.08\,h*0.85)';
    }

    private function ffmpeg(): string
    {
        return (string) config('media.ffmpeg_bin', 'ffmpeg');
    }
}
