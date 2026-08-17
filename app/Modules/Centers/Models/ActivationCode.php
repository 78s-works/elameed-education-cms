<?php

namespace App\Modules\Centers\Models;

use App\Models\User;
use App\Modules\Centers\Enums\CodeStatus;
use App\Modules\Centers\Enums\CodeType;
use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\HasContentTarget;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time recharge / activation code (M12). A `wallet`-type code is a payment
 * scratch code (B22): denominated by `amount_minor`, minted in a `batch`, single-use,
 * tenant-scoped, credits the student wallet by exactly its denomination on redeem.
 */
class ActivationCode extends Model
{
    use BelongsToTenant;
    use HasContentTarget;
    use HasUuids;

    protected $fillable = [
        'code',
        'type',
        'amount_minor',
        'target_type',
        'target_id',
        'center_id',
        'generated_by',
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
        'target_id' => 'integer',
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

    /** Active but past its expiry — an unusable "expired" scratch code (B22 list filter). */
    public function isExpired(): bool
    {
        return $this->status === CodeStatus::Active
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
