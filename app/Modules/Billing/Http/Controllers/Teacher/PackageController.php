<?php

namespace App\Modules\Billing\Http\Controllers\Teacher;

use App\Modules\Billing\Http\Resources\PackageResource;
use App\Modules\Billing\Models\SubscriptionPackage;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /teacher/packages (M03) — the catalogue of active plans a teacher can
 * compare when considering a change, each flagged with `is_current` for the
 * tenant's own plan. Read-only: the actual switch is still performed by the
 * platform admin (a teacher self-serve/upgrade flow with payment is a separate
 * feature). Tenant-scoped (role:teacher).
 *
 * The teacher's own current plan + usage lives at GET /teacher/subscription.
 */
class PackageController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) $this->context->tenant()->getKey();

        $current = $this->subscriptions->current($tenantId);
        $currentPackageId = $current !== null ? (int) $current->package_id : null;

        // Active, non-retired plans only — the same catalogue order as admin.
        $packages = SubscriptionPackage::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $data = $packages->map(fn (SubscriptionPackage $package): array => array_merge(
            (new PackageResource($package))->resolve(),
            ['is_current' => $package->getKey() === $currentPackageId],
        ))->all();

        return response()->json(['data' => $data]);
    }
}
