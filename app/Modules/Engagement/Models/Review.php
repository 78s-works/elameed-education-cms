<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\HasContentTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rating + comment on a content target — EITHER a standalone lesson OR a
 * recursive package (`target_type`/`target_id`, VD §7 — `courses` retired). Either
 * a student's own review (one per student per target, `user_id` set) or a
 * teacher-authored testimonial (`user_id` null, `author_name` set), managed from
 * the teacher panel. Feeds the landing `testimonials` section — visible rows only.
 */
class Review extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;
    use HasContentTarget;

    protected $fillable = [
        'academic_year_id',
        'target_type',
        'target_id',
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
        'target_id' => 'integer',
        'rating' => 'integer',
        'is_visible' => 'boolean',
    ];

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
