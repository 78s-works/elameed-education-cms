<?php

namespace App\Modules\Centers\Http\Controllers;

use App\Modules\Centers\Http\Requests\RedeemCodeRequest;
use App\Modules\Centers\Services\CodeRedemptionService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /codes/redeem (M12) — a student redeems an activation code → wallet credit
 * or course enrollment.
 */
class RedeemCodeController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CodeRedemptionService $redemption,
    ) {}

    public function __invoke(RedeemCodeRequest $request): JsonResponse
    {
        $result = $this->redemption->redeem(
            $this->context->tenantOrFail()->getKey(),
            $request->validated('code'),
            $request->user(),
        );

        return response()->json(['data' => $result]);
    }
}
