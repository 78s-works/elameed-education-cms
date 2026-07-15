<?php

namespace App\Modules\Media\Models;

use App\Modules\Media\Enums\MediaVersionState;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a video on the Media Host. The owning asset points at its
 * current servable version; a replacement is a NEW version that only becomes
 * current once it reaches `ready` (docs/MEDIA_HOST_API_v1.md §5).
 *
 * @property MediaVersionState $state
 */
class MediaVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'media_asset_id', 'version', 'provider', 'state',
        'host_video_id', 'playback_id', 'thumbnail_url', 'duration_sec', 'meta', 'error', 'ready_at',
    ];

    protected $casts = [
        'state' => MediaVersionState::class,
        'meta' => 'array',
        'version' => 'integer',
        'duration_sec' => 'integer',
        'ready_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function uploadSessions(): HasMany
    {
        return $this->hasMany(MediaUploadSession::class, 'media_version_id');
    }

    public function isReady(): bool
    {
        return $this->state === MediaVersionState::Ready;
    }
}
