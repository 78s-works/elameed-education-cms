<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paper (offline, in-center) exam score recorded against a student (VD R12,
 * doc 13 Phase 15). Tenant-scoped and year-scoped; addressed publicly by uuid.
 * Lightweight — no questions/attempts, just the typed-in result.
 */
class CenterExamGrade extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'center_id',
        'student_user_id',
        'title',
        'total_marks',
        'score',
        'sat_on',
        'entered_by',
        'note',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'score' => 'decimal:2',
        'sat_on' => 'date',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
