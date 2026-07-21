<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants a student access to a course OR a single unit — the single source of
 * truth for access (03_Data_Model.md §5). A row carries either `course_id`
 * (whole-course access) or `unit_id` (one chapter, from a package). `bundle_id`
 * records the package the grant came from, when applicable.
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
        'bundle_id',
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
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
