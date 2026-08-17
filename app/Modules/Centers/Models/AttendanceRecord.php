<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's attendance at a center (M12). A row with `center_session_id = null`
 * is plain per-day attendance; a row with a session is a check-in that opened all
 * of that session's lessons online until `access_expires_at` (center check-in →
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
        'center_session_id',
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

    /** The session the student checked in for (null for plain day-attendance). */
    public function centerSession(): BelongsTo
    {
        return $this->belongsTo(CenterSession::class);
    }
}
