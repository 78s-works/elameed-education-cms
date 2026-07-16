<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Modules\Billing\Http\Requests\AssignSubscriptionRequest;
use App\Modules\Billing\Http\Resources\TenantSubscriptionResource;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * /admin/tenants/{tenant}/subscription (M03, FR-M03-03) — assign / upgrade /
 * downgrade a teacher academy's plan, with an optional new-teacher discount.
 * Central host + platform admin only.
 */
class TenantSubscriptionController
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function show(Tenant $tenant): JsonResponse
    {
        $current = $this->subscriptions->current((int) $tenant->getKey());

        return response()->json([
            'data' => $current ? (new TenantSubscriptionResource($current))->resolve() : null,
        ]);
    }

    public function store(AssignSubscriptionRequest $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validated();
        $package = SubscriptionPackage::where('uuid', $data['package_uuid'])->firstOrFail();

        $meta = array_filter(['discount_reason' => $data['discount_reason'] ?? null]);

        $subscription = $this->subscriptions->assign(
            $tenant,
            $package,
            $data['price_minor'] ?? null,
            $data['trial_days'] ?? null,
            $meta,
        );

        app(AuditLogger::class)->log(
            'tenant.subscription.assigned',
            ['tenant' => $tenant->slug, 'package' => $package->slug, 'price_minor' => $subscription->price_minor],
            (int) $tenant->getKey(),
            'tenant_subscription',
            $subscription->id,
        );

        return (new TenantSubscriptionResource($subscription->loadMissing('package')))
            ->response()->setStatusCode(201);
    }
}
