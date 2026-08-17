<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Enums\CouponType;
use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\HasContentTarget;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A discount coupon / promo code (M21). Tenant-scoped; addressed publicly by code
 * (unique per tenant) and by uuid on the teacher panel.
 *
 * @property CouponType $type
 */
class Coupon extends Model
{
    use BelongsToTenant;
    use HasContentTarget;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'value',
        'target_type',
        'target_id',
        'min_subtotal_minor',
        'usage_limit',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected $attributes = [
        'type' => 'percent',
        'is_active' => true,
        'used_count' => 0,
    ];

    protected $casts = [
        'type' => CouponType::class,
        'value' => 'integer',
        'target_id' => 'integer',
        'min_subtotal_minor' => 'integer',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Whether the coupon is currently usable (ignores cart-specific rules). */
    public function isRedeemable(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at !== null && $now->gt($this->expires_at)) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /** The discount (minor units) this coupon yields against an applicable base. */
    public function discountFor(int $applicableBaseMinor): int
    {
        if ($applicableBaseMinor <= 0) {
            return 0;
        }

        $discount = $this->type === CouponType::Percent
            ? (int) floor($applicableBaseMinor * min(100, max(0, $this->value)) / 100)
            : (int) $this->value;

        return max(0, min($discount, $applicableBaseMinor));
    }
}
