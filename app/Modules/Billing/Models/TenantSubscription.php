<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher academy's plan assignment + billing state (M03). GLOBAL (has
 * tenant_id but no RLS / BelongsToTenant): the platform admin manages it
 * cross-tenant; the owning teacher reads it via an explicit tenant_id filter —
 * same rationale as `tenant_user`. See 03_Data_Model.md §3.
 *
 * @property SubscriptionStatus $status
 */
class TenantSubscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'package_id',
        'status',
        'price_minor',
        'currency',
        'started_at',
        'trial_ends_at',
        'renews_at',
        'ends_at',
        'canceled_at',
        'meta',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'status' => 'active',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'price_minor' => 'integer',
        'started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'renews_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPackage::class, 'package_id');
    }
}
