<?php

namespace App\Modules\Centers\Models;

use App\Modules\Catalog\Models\Lesson;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, timed session held at a center that bundles 0+ lessons. Attendance is
 * taken against a session; a center check-in opens all the session's lessons
 * online for the student. Year-scoped (its lessons live under an academic year).
 */
class CenterSession extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'academic_year_id',
        'center_id',
        'name',
        'session_at',
    ];

    protected $casts = [
        'session_at' => 'datetime',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /** The lessons this session opens on check-in (0+). */
    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'center_session_lesson');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
