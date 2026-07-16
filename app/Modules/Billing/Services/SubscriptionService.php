<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns tenant ↔ package assignment (M03): supersede the current plan, open a new
 * subscription, and keep the denormalised pointer on `tenants` in sync.
 */
class SubscriptionService
{
    /**
     * Assign (or upgrade/downgrade) a tenant to a package. Supersedes any current
     * subscription and opens a fresh one. Price / trial may be overridden for a
     * new-teacher discount (FR-M03-04).
     *
     * @param  array<string, mixed>  $meta
     */
    public function assign(
        Tenant $tenant,
        SubscriptionPackage $package,
        ?int $priceMinor = null,
        ?int $trialDays = null,
        array $meta = [],
    ): TenantSubscription {
        return DB::transaction(function () use ($tenant, $package, $priceMinor, $trialDays, $meta): TenantSubscription {
            $now = Carbon::now();
            $trialDays ??= (int) $package->trial_days;
            $price = $priceMinor ?? (int) $package->price_minor;

            // Close out whatever the tenant is currently on.
            TenantSubscription::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('status', [
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->update([
                    'status' => SubscriptionStatus::Canceled->value,
                    'canceled_at' => $now,
                    'ends_at' => $now,
                ]);

            $trialEndsAt = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;
            $paidPeriodStart = $trialEndsAt ?? $now;

            $subscription = TenantSubscription::create([
                'tenant_id' => $tenant->getKey(),
                'package_id' => $package->getKey(),
                'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                'price_minor' => $price,
                'currency' => $package->currency,
                'started_at' => $now,
                'trial_ends_at' => $trialEndsAt,
                'renews_at' => $this->addInterval($paidPeriodStart, $package->interval),
                'meta' => $meta !== [] ? $meta : null,
            ]);

            $tenant->forceFill([
                'package_id' => $package->getKey(),
                'trial_ends_at' => $trialEndsAt,
            ])->save();

            return $subscription;
        });
    }

    /** The tenant's current (trialing/active/past_due) subscription, if any. */
    public function current(int $tenantId): ?TenantSubscription
    {
        return TenantSubscription::query()
            ->with('package')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->latest('id')
            ->first();
    }

    private function addInterval(Carbon $from, BillingInterval $interval): Carbon
    {
        return $interval === BillingInterval::Yearly
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();
    }
}
