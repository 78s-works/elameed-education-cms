<?php

namespace App\Modules\Media\Services;

use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaRendition;
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
        $dir = "media/hls/{$asset->uuid}/{$viewer->getKey()}";

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
        $disk->makeDirectory($dir);

        // Per-transcode scratch dir. FFmpeg RUNS from here, so the drawtext filter
        // references font/text by bare relative names — avoiding the Windows
        // drive-letter colon that FFmpeg's filtergraph parser can't escape cleanly.
        $tmp = 'media/tmp/'.bin2hex(random_bytes(8));
        $disk->makeDirectory($tmp);

        $norm = static fn (string $p): string => str_replace('\\', '/', $p);
        $source = $norm($disk->path($sourceKey));
        $outDir = $norm($disk->path($dir));
        $tmpDir = $norm($disk->path($tmp));

        copy((string) config('media.watermark.font'), $tmpDir.'/font.ttf');
        file_put_contents($tmpDir.'/wm.txt', $watermark);
        file_put_contents($tmpDir.'/enc.key', $key); // FFmpeg reads the raw key to encrypt
        // key_info: line1 = URI placeholder (rewritten per-request at serve time),
        // line2 = key file, line3 = IV hex.
        file_put_contents($tmpDir.'/keyinfo', "__KEYURI__\n".$tmpDir."/enc.key\n".$iv."\n");

        try {
            $result = Process::path($tmpDir)->timeout(600)->run([
                $this->ffmpeg(),
                '-y',
                '-i', $source,
                '-vf', $this->watermarkFilter(),
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '128k',
                '-hls_time', (string) config('media.hls_time', 6),
                '-hls_playlist_type', 'vod',
                '-hls_key_info_file', $tmpDir.'/keyinfo',
                '-hls_segment_filename', $outDir.'/seg_%03d.ts',
                $outDir.'/index.m3u8',
            ]);

            if (! $result->successful()) {
                throw new RuntimeException('FFmpeg failed: '.mb_substr($result->errorOutput() ?: $result->output(), -1500));
            }
        } finally {
            $disk->deleteDirectory($tmp); // key material never lingers on disk
        }

        return count(glob($outDir.'/seg_*.ts') ?: []);
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
