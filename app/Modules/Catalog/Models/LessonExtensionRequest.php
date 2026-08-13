<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Catalog\Enums\ExtensionStatus;
use App\Support\Traits\BelongsToAcademicYear;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's request to extend a lesson access window. Staff decide; a grant
 * pushes the window out by the lesson's extension_hours.
 *
 * @property ExtensionStatus $status
 */
class LessonExtensionRequest extends Model
{
    use BelongsToAcademicYear;
    use BelongsToTenant;

    protected $fillable = [
        'access_window_id',
        'academic_year_id',
        'user_id',
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
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function accessWindow(): BelongsTo
    {
        return $this->belongsTo(LessonAccessWindow::class, 'access_window_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExtensionStatus::Pending->value);
    }
}
