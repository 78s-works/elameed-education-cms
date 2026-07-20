<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\VideoSource;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Traits\BelongsToTenant;
use App\Support\Youtube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ContentVisibility $visibility
 * @property VideoSource $active_video_source
 */
class Lesson extends Model
{
    use BelongsToTenant;

    /** In-memory defaults matching the DB defaults (so a fresh model has them). */
    protected $attributes = [
        'visibility' => 'visible',
        'active_video_source' => 'upload',
    ];

    protected $fillable = [
        'unit_id',
        'course_id',
        'title',
        'description',
        'sort_order',
        'video_asset_id',
        'youtube_url',
        'active_video_source',
        'duration_sec',
        'max_views',
        'is_free_preview',
        'gating_rule',
        'visibility',
        'publish_at',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'active_video_source' => VideoSource::class,
        'publish_at' => 'datetime',
        'is_free_preview' => 'boolean',
        'gating_rule' => 'array',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The MANY assets of a lesson — its supporting materials (pdf/file/link).
     * Excludes the video, which is the single `videoAsset` below.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAsset::class)->where('type', '!=', MediaType::HlsVideo->value);
    }

    /** Alias of attachments() — reads as "a lesson HAS MANY assets". */
    public function assets(): HasMany
    {
        return $this->attachments();
    }

    /** The ONE video of a lesson (referenced by lessons.video_asset_id). */
    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'video_asset_id');
    }

    /** Alias of videoAsset() — reads as "a lesson HAS ONE video". */
    public function video(): BelongsTo
    {
        return $this->videoAsset();
    }

    /**
     * Does the lesson's ACTIVE video source have something playable?
     * YouTube → a valid link; upload → a linked video asset. Used for the
     * source-aware `has_video` flag exposed to clients.
     */
    public function hasActiveVideo(): bool
    {
        return $this->active_video_source === VideoSource::Youtube
            ? Youtube::isValid($this->youtube_url)
            : $this->video_asset_id !== null;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('visibility', ContentVisibility::Visible->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }
}
