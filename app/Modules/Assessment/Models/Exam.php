<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Enums\ExamMode;
use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ExamType $type
 * @property ExamMode $mode
 */
class Exam extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $attributes = [
        'type' => 'exam',
        'mode' => 'standard',
    ];

    protected $fillable = [
        'course_id', 'lesson_id', 'title', 'type', 'pass_percent', 'duration_min',
        'attempts_allowed', 'question_order', 'scoring', 'starts_at', 'ends_at',
        'result_visibility', 'show_answers', 'depends_on_exam_id', 'mode', 'is_published',
    ];

    protected $casts = [
        'type' => ExamType::class,
        'mode' => ExamMode::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'show_answers' => 'boolean',
        'is_published' => 'boolean',
        'pass_percent' => 'integer',
        'attempts_allowed' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** Published and within its (optional) availability window. */
    public function isOpen(): bool
    {
        return $this->is_published
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
