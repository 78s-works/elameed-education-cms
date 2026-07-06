<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property OrderStatus $status
 */
class Order extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'total_minor',
        'currency',
        'coupon_id',
        'status',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'status' => 'pending',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total_minor' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }
}
