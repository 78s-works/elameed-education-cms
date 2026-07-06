<?php

namespace App\Modules\Engagement\Models;

use App\Modules\Catalog\Models\Lesson;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    use BelongsToTenant;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'enrollment_id',
        'lesson_id',
        'user_id',
        'watch_percent',
        'watch_seconds',
        'sessions_count',
        'last_position_sec',
        'completed_at',
    ];

    protected $casts = [
        'watch_percent' => 'integer',
        'watch_seconds' => 'integer',
        'sessions_count' => 'integer',
        'last_position_sec' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
