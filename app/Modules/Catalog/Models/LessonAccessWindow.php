<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's time-boxed access window for one lesson. Opens on start, closes
 * at `expires_at` (or when `locked_at` is stamped). The countdown timer reads
 * `remainingSeconds()`; the playback gate calls `isLocked()`.
 */
class LessonAccessWindow extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'academic_year_id',
        'started_at',
        'expires_at',
        'locked_at',
        'extensions_used',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'locked_at' => 'datetime',
        'extensions_used' => 'integer',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function extensionRequests(): HasMany
    {
        return $this->hasMany(LessonExtensionRequest::class, 'access_window_id');
    }

    /** Closed when explicitly locked or past expiry. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null || $this->expires_at->isPast();
    }

    /** Whole seconds remaining, floored at 0. Timestamp math avoids Carbon diff-sign differences. */
    public function remainingSeconds(): int
    {
        if ($this->isLocked()) {
            return 0;
        }

        return max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
    }
}
