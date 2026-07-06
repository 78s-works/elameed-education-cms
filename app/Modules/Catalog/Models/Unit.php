<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ContentVisibility;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ContentVisibility $visibility
 */
class Unit extends Model
{
    use BelongsToTenant;

    /** In-memory defaults matching the DB defaults (so a fresh model has them). */
    protected $attributes = [
        'visibility' => 'visible',
    ];

    protected $fillable = [
        'course_id',
        'title',
        'sort_order',
        'visibility',
        'publish_at',
    ];

    protected $casts = [
        'visibility' => ContentVisibility::class,
        'publish_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('visibility', ContentVisibility::Visible->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
    }
}
