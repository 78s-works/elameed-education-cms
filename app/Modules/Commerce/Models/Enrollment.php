<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants a student access to a course, or a single lesson — the single source of
 * truth for access (03_Data_Model.md §5). A row carries `course_id` (whole
 * course), `lesson_id` (one lesson), or `exam_id`. A package purchase fans out
 * into per-lesson rows (B15 / VD LP-D2); each carries the source `package_id` as
 * provenance (never an access key — access is always by `lesson_id`). `unit_id` /
 * `bundle_id` are dormant columns (Unit + Bundle retired — VD change set §7).
 *
 * @property EnrollmentStatus $status
 * @property EnrollmentSource $source
 */
class Enrollment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'course_id',
        'unit_id',
        'lesson_id',
        'exam_id',
        'bundle_id',
        'package_id',
        'source',
        'starts_at',
        'expires_at',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'source' => 'purchase',
    ];

    protected $casts = [
        'status' => EnrollmentStatus::class,
        'source' => EnrollmentSource::class,
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** The package this per-lesson grant fanned out from, when it did (B15). */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Assessment\Models\Exam::class);
    }

    /** Active, started, and not past its access window. */
    public function scopeGrantsAccess(Builder $query): Builder
    {
        return $query
            ->where('status', EnrollmentStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }
}
