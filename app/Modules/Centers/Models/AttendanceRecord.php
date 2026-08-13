<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's attendance at a center on a given day (M12).
 */
class AttendanceRecord extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'academic_year_id',
        'center_id',
        'user_id',
        'course_id',
        'attended_on',
        'status',
        'marked_by',
        'source',
        'external_ref',
        'note',
    ];

    protected $casts = [
        'attended_on' => 'date',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
