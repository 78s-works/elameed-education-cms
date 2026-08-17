<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Modules\Catalog\Models\LessonSection;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's attendance at a center (M12). A row with `lesson_section_id = null`
 * is plain per-day attendance; a row with a section is a check-in that opened
 * that part's parent lesson online until `access_expires_at` (center check-in →
 * time-boxed access).
 */
class AttendanceRecord extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'academic_year_id',
        'center_id',
        'user_id',
        'lesson_section_id',
        'access_expires_at',
        'attended_on',
        'status',
        'marked_by',
        'source',
        'external_ref',
        'note',
    ];

    protected $casts = [
        'attended_on' => 'date',
        'access_expires_at' => 'datetime',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The lesson part the student checked in for (null for plain day-attendance). */
    public function lessonSection(): BelongsTo
    {
        return $this->belongsTo(LessonSection::class);
    }
}
