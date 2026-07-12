<?php

namespace App\Modules\Media\Models;

use App\Models\User;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-student, watermark-burned, AES-128-encrypted HLS transcode of a
 * MediaAsset (02_Architecture.md §7.3). The content key lives in `enc_key`
 * encrypted at rest and is released only through the token-gated key endpoint.
 */
class MediaRendition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'media_asset_id',
        'user_id',
        'status',
        'hls_dir',
        'enc_key',
        'iv',
        'segment_count',
        'error',
    ];

    protected $casts = [
        'enc_key' => 'encrypted',
        'segment_count' => 'integer',
    ];

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
