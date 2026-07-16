<?php

namespace App\Modules\Billing\Http\Controllers\Teacher;

use App\Modules\Billing\Http\Resources\TenantSubscriptionResource;
use App\Modules\Billing\Services\PackageUsage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /teacher/subscription (M03) — the resolved tenant's current plan, its
 * limits, and current usage against them. Read-only: the plan itself is managed
 * by the platform admin. Tenant-scoped (role:teacher).
 */
class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PackageUsage $usage,
        private readonly TenantContext $context,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = (int) $this->context->tenant()->getKey();

        $current = $this->subscriptions->current($tenantId);
        $package = $current?->package;

        return response()->json([
            'data' => [
                'subscription' => $current ? (new TenantSubscriptionResource($current))->resolve() : null,
                'usage' => $this->usage->forTenant($tenantId, $package),
            ],
        ]);
    }
}
