<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Modules\Centers\Enums\CodeStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sequential, grade-encoded student-identity code minted per center (B20).
 * Distinct from ActivationCode (M12, random wallet/course recharge): this code
 * is handed to a student and, at sign-up, binds them to `center_id` + `grade`
 * (+ study_mode). Reuses CodeStatus (active=unused, redeemed=used, disabled).
 */
class CenterIdCode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'center_id',
        'grade',
        'sequence',
        'code',
        'status',
        'batch_id',
        'generated_by',
        'used_by',
        'used_at',
    ];

    protected $casts = [
        'status' => CodeStatus::class,
        'grade' => 'integer',
        'sequence' => 'integer',
        'used_at' => 'datetime',
    ];

    /**
     * The encoded grade digit → the student-profile academic_year label it binds
     * at registration (B21). 1/2/3 = 1st/2nd/3rd secondary; the Arabic labels
     * match the sign-up form / seeder convention for student_profiles.academic_year.
     */
    public const GRADE_LABELS = [
        1 => 'الصف الأول الثانوي',
        2 => 'الصف الثاني الثانوي',
        3 => 'الصف الثالث الثانوي',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** True while the code has not yet been consumed at registration. */
    public function isUnused(): bool
    {
        return $this->status === CodeStatus::Active;
    }

    /** The academic_year label this code's grade binds onto the student profile. */
    public function gradeLabel(): string
    {
        return self::GRADE_LABELS[$this->grade] ?? (string) $this->grade;
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
