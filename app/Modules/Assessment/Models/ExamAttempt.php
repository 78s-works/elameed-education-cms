<?php

namespace App\Modules\Assessment\Models;

use App\Models\User;
use App\Modules\Assessment\Enums\AttemptStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AttemptStatus $status
 * @property array<string, mixed>|null $answers
 */
class ExamAttempt extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected $fillable = [
        'exam_id', 'user_id', 'attempt_number', 'started_at', 'submitted_at',
        'score', 'max_score', 'status', 'answers', 'needs_manual_grade',
    ];

    protected $casts = [
        'status' => AttemptStatus::class,
        'answers' => 'array',
        'needs_manual_grade' => 'boolean',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'integer',
        'max_score' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
