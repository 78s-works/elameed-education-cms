<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A teacher **package**: a priced group of courses and/or units sold as one
 * product. Purchasing it grants an enrollment per contained item, so all the
 * lessons/exams inside open at once. Mirrors Course for slug/visibility/access.
 *
 * @property ContentVisibility $visibility
 */
class Bundle extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $attributes = [
        'visibility' => 'hidden',
    ];

    protected $fillable = [
        'title',
        'subtitle',
        'slug',
        'description',
        'price_minor',
        'currency',
        'access_days',
        'visibility',
        'publish_at',
        'is_free',
        'purchase_enabled',
        'cover_url',
        'thumbnail_url',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'publish_at' => 'datetime',
        'is_free' => 'boolean',
        'purchase_enabled' => 'boolean',
        'price_minor' => 'integer',
        'access_days' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
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

    /** Build a slug unique within the current tenant (mirrors Course). */
    public static function makeUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'b-'.Str::lower(Str::random(6));
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
