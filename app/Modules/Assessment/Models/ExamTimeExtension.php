<?php

namespace App\Modules\Assessment\Models;

use App\Models\User;
use App\Modules\Catalog\Enums\ExtensionStatus;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's request for extra time on an exam/quiz (doc 11 R6), plus the staff
 * decision. When granted, `granted_minutes` is added to the exam's `duration_min`
 * for this student by the attempt timer. Reuses the shared ExtensionStatus enum.
 *
 * @property ExtensionStatus $status
 */
class ExamTimeExtension extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'academic_year_id',
        'exam_id',
        'user_id',
        'requested_minutes',
        'granted_minutes',
        'status',
        'requested_at',
        'decided_at',
        'decided_by',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => ExtensionStatus::class,
        'requested_minutes' => 'integer',
        'granted_minutes' => 'integer',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExtensionStatus::Pending->value);
    }

    public function scopeGranted(Builder $query): Builder
    {
        return $query->where('status', ExtensionStatus::Granted->value);
    }
}
