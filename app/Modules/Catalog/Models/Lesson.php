<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ContentVisibility $visibility
 */
class Lesson extends Model
{
    use BelongsToTenant;

    /** In-memory defaults matching the DB defaults (so a fresh model has them). */
    protected $attributes = [
        'visibility' => 'visible',
    ];

    protected $fillable = [
        'unit_id',
        'course_id',
        'title',
        'description',
        'sort_order',
        'video_asset_id',
        'duration_sec',
        'max_views',
        'is_free_preview',
        'gating_rule',
        'visibility',
        'publish_at',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
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

    /** Attachments (pdf/file/link) linked to this lesson. */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** The primary video asset, if transcoded (set by the Media step). */
    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'video_asset_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('visibility', ContentVisibility::Visible->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }
}
