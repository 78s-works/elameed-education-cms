<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rating + comment on a course. Either a student's own review (one per student
 * per course, `user_id` set) or a teacher-authored testimonial (`user_id` null,
 * `author_name` set), managed from the teacher panel. Feeds the landing
 * `testimonials` section and a course's aggregate rating — visible rows only.
 */
class Review extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'course_id',
        'user_id',
        'author_name',
        'rating',
        'comment',
        'is_visible',
    ];

    /** In-memory default matching the DB default. */
    protected $attributes = [
        'is_visible' => true,
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Only publicly-shown reviews (the moderation gate). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /** Display name: the linked student, else the teacher-authored author name. */
    public function displayName(): ?string
    {
        return $this->user?->name ?? $this->author_name;
    }
}
