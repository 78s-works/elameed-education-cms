<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A teacher subscription plan (M03). GLOBAL entity — defined by the platform and
 * offered to every academy; not tenant-scoped. See 03_Data_Model.md §3.
 *
 * @property BillingInterval $interval
 * @property array<string,int|null>|null $limits
 */
class SubscriptionPackage extends Model
{
    use HasUuids;
    use SoftDeletes;

    /** Canonical limit keys (FR-M03-02); a null value means "unlimited". */
    public const LIMIT_KEYS = ['max_students', 'max_courses', 'storage_mb', 'max_assistants'];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_minor',
        'currency',
        'interval',
        'trial_days',
        'limits',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'interval' => 'monthly',
        'is_active' => true,
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
        'limits' => 'array',
        'is_active' => 'boolean',
        'interval' => BillingInterval::class,
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'package_id');
    }

    /** Limit value for a canonical key, or null when unlimited / unset. */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
