<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property ContentVisibility $visibility
 */
class Course extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    /** In-memory defaults matching the DB defaults (so a fresh model has them). */
    protected $attributes = [
        'visibility' => 'hidden',
    ];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'category_id',
        'price_minor',
        'currency',
        'access_days',
        'visibility',
        'publish_at',
        'is_free',
        'purchase_enabled',
        'is_center',
        'cover_url',
        'points',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'publish_at' => 'datetime',
        'is_free' => 'boolean',
        'purchase_enabled' => 'boolean',
        'is_center' => 'boolean',
        'price_minor' => 'integer',
        'points' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** Visible AND either unscheduled or past its publish time. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('visibility', ContentVisibility::Visible->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }

    public function isPublished(): bool
    {
        return $this->visibility === ContentVisibility::Visible
            && ($this->publish_at === null || $this->publish_at->lessThanOrEqualTo(now()));
    }

    /**
     * Build a slug unique within the current tenant. Falls back to a random stem
     * when the title has no ASCII (e.g. Arabic titles) so the public URL stays valid.
     */
    public static function makeUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'c-'.Str::lower(Str::random(6));
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
