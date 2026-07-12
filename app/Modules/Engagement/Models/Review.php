<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's rating + comment on a course (one per student per course).
 * Read-only input to the landing `testimonials` section and a course's
 * aggregate rating.
 */
class Review extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'course_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
