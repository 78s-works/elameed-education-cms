<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platform-subscription charge against an academy (ADM-16). GLOBAL, for the
 * same reason as {@see TenantSubscription}: the platform admin reads it across
 * every tenant, so it carries `tenant_id` without a tenant scope.
 *
 * Money is minor units, never floats — same contract as the rest of the system.
 */
class TenantSubscriptionCharge extends Model
{
    use HasUuids;

    /** Settlement states a charge can be in. */
    public const STATUSES = ['paid', 'pending', 'failed', 'refunded'];

    /** How the money arrived. Platform plans are settled off-gateway today. */
    public const METHODS = ['manual', 'bank_transfer', 'instapay', 'card', 'wallet', 'other'];

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'amount_minor',
        'currency',
        'status',
        'method',
        'period_start',
        'period_end',
        'charged_at',
        'reference',
        'notes',
        'recorded_by',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'status' => 'paid',
        'method' => 'manual',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'charged_at' => 'datetime',
    ];

    /** The generated uuid is the public identifier; the key stays auto-increment. */
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
