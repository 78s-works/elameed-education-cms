<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual staff-granted access override: student `user_id` may open the target
 * (one of `lesson_id` / `section_id` / `unit_id`) even if its dependencies are
 * unmet. Active while `revoked_at` is null.
 */
class ContentAccessOverride extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'academic_year_id',
        'lesson_id',
        'section_id',
        'granted_by',
        'note',
        'granted_at',
        'revoked_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Not yet revoked. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LessonSection::class, 'section_id');
    }
}
