<?php

namespace App\Modules\Centers\Models;

use App\Modules\Catalog\Models\Course;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time recharge / activation code (M12).
 */
class ActivationCode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'code',
        'type',
        'amount_minor',
        'course_id',
        'center_id',
        'batch',
        'status',
        'redeemed_by',
        'redeemed_at',
        'expires_at',
    ];

    protected $casts = [
        'type' => CodeType::class,
        'status' => CodeStatus::class,
        'amount_minor' => 'integer',
        'redeemed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isRedeemable(): bool
    {
        return $this->status === CodeStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
