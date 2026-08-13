<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher-granted manual pass on a must_pass part (VD change set §7 LP-D3).
 * Its presence makes progression treat the student as having passed the part,
 * regardless of score or exhausted retakes. Tenant-scoped; one row per (part,
 * student) — the DB unique constraint is the source of the 409 on a duplicate.
 */
class PartPassOverride extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'lesson_section_id',
        'academic_year_id',
        'user_id',
        'granted_by',
        'granted_at',
        'note',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(LessonSection::class, 'lesson_section_id');
    }

    /** The student who was granted the pass. */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The teacher/assistant who granted it. */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
