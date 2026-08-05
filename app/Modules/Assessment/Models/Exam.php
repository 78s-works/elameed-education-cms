<?php

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Enums\ExamGradingMode;
use App\Modules\Assessment\Enums\ExamMode;
use App\Modules\Assessment\Enums\ExamPassMode;
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
        'type' => 'free_exam',
        'mode' => 'standard',
    ];

    // `depends_on_exam_id` is retired (exam→exam gating removed). Column kept
    // dormant in the DB; deliberately not fillable so nothing writes it.
    protected $fillable = [
        'course_id', 'lesson_id', 'unit_id', 'title', 'type', 'pass_percent', 'duration_min',
        'max_time_extensions', 'attempts_allowed', 'question_order', 'scoring', 'starts_at', 'ends_at',
        'result_visibility', 'show_answers', 'mode', 'is_published',
        // Degree of success (VD change set §7, LP-11/LP-12).
        'pass_mode', 'pass_value', 'total_marks', 'grading_mode',
    ];

    protected $casts = [
        'type' => ExamType::class,
        'mode' => ExamMode::class,
        'pass_mode' => ExamPassMode::class,
        'grading_mode' => ExamGradingMode::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'show_answers' => 'boolean',
        'is_published' => 'boolean',
        'pass_percent' => 'integer',
        'pass_value' => 'decimal:2',
        'total_marks' => 'decimal:2',
        'attempts_allowed' => 'integer',
        'duration_min' => 'integer',
        'max_time_extensions' => 'integer',
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

    // `unit_id` is a dormant column (Unit retired — VD change set §7; the units
    // table was dropped, lessons/packages replace it). No relation any more.

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

    /**
     * Did an attempt scoring $score out of $maxScore meet this exam's "degree of
     * success" (VD change set §7, LP-11)? Prefers the pass_mode/pass_value pair
     * when a pass_value is set — marks mode compares the absolute score, percent
     * mode compares the ratio — and falls back to the legacy pass_percent
     * otherwise. Null when there is nothing to score against.
     */
    public function passed(int|float $score, int|float $maxScore): ?bool
    {
        if ($maxScore <= 0) {
            return null;
        }

        if ($this->pass_value !== null) {
            if ($this->pass_mode === ExamPassMode::Marks) {
                return (float) $score >= (float) $this->pass_value;
            }

            return ($score / $maxScore * 100) >= (float) $this->pass_value;
        }

        return ($score / $maxScore * 100) >= (int) $this->pass_percent;
    }
}
