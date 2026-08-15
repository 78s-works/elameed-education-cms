<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Enums\ContentVisibility;
use App\Modules\Catalog\Enums\VideoSource;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Models\MediaAsset;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use App\Support\Youtube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A standalone lesson (VD change set §7 LP-3): created independently, scoped to
 * one academic year, sellable alone, and reusable across packages within its
 * year. Its `access_mode` is the channel ceiling every part must fit within.
 *
 * @property ContentVisibility $visibility
 * @property VideoSource $active_video_source
 * @property AccessMode $access_mode
 */
class Lesson extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    /** In-memory defaults matching the DB defaults (so a fresh model has them). */
    protected $attributes = [
        'visibility' => 'visible',
        'active_video_source' => 'upload',
        'access_mode' => 'both',
    ];

    protected $fillable = [
        'academic_year_id',
        'unit_id',
        'course_id',
        'access_mode',
        'title',
        'description',
        'sort_order',
        'video_asset_id',
        'youtube_url',
        'active_video_source',
        'duration_sec',
        'max_views',
        'availability_days',
        'max_extensions',
        'extension_hours',
        'self_reopen_limit',
        'is_free_preview',
        'price_minor',
        'currency',
        'is_purchasable',
        'visibility',
        'publish_at',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'active_video_source' => VideoSource::class,
        'access_mode' => AccessMode::class,
        'publish_at' => 'datetime',
        'is_free_preview' => 'boolean',
        'is_purchasable' => 'boolean',
        'availability_days' => 'integer',
        'max_extensions' => 'integer',
        'extension_hours' => 'integer',
        'self_reopen_limit' => 'integer',
        'price_minor' => 'integer',
    ];

    /**
     * Safety net for the NOT NULL academic_year_id: the API always has a resolved
     * year (BelongsToAcademicYear fills it from the X-Academic-Year context), but
     * lessons created outside a request (seeders, tests, back-office scripts) have
     * none — fall back to the tenant's Default year, creating it if absent. In
     * production this never fires; the request context wins first.
     */
    protected static function booted(): void
    {
        static::creating(function (Lesson $lesson): void {
            if (! empty($lesson->academic_year_id) || empty($lesson->tenant_id)) {
                return;
            }

            $lesson->academic_year_id = AcademicYear::withoutGlobalScopes()
                ->where('tenant_id', $lesson->tenant_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id')
                ?? AcademicYear::withoutGlobalScopes()->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $lesson->tenant_id,
                    'name' => 'Default',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        // VD-D1c: deleting a lesson auto-detaches it from every package. item_id
        // on package_items is polymorphic (lesson|package) so it can't be a hard
        // FK — this hook is the cascade. Scoped to the lesson's tenant, regardless
        // of the active academic-year scope.
        static::deleting(function (Lesson $lesson): void {
            PackageItem::withoutGlobalScopes()
                ->where('tenant_id', $lesson->tenant_id)
                ->where('item_type', PackageItem::TYPE_LESSON)
                ->where('item_id', $lesson->id)
                ->delete();
        });
    }

    /** Does this lesson enforce a time-boxed access window? */
    public function hasAvailabilityWindow(): bool
    {
        return $this->availability_days !== null && $this->availability_days > 0;
    }

    // `unit_id` is a dormant column (Unit retired — VD change set §7; lessons are
    // standalone and grouped by packages now). No relation any more.

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

    /** Typed content sections (FR-M04-01). Order via the section `ordered` scope. */
    public function sections(): HasMany
    {
        return $this->hasMany(LessonSection::class);
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
            ->where(fn(Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }
}
