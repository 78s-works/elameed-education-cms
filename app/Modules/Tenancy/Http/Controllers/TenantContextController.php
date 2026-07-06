<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Resources\TenantContextResource;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/tenant/context — resolves the current host to a tenant and returns
 * its branding/theme/features for the SPA to boot. Public (no auth).
 * See 04_API_Specification.md → "Tenant context & branding".
 */
class TenantContextController
{
    public function __invoke(TenantContext $context): TenantContextResource|JsonResponse
    {
        if (! $context->hasTenant()) {
            return response()->json([
                'error' => [
                    'code' => 'tenant_not_found',
                    'message' => 'لا يوجد حساب مرتبط بهذا العنوان.', // No academy configured for this address.
                ],
            ], 404);
        }

        return new TenantContextResource($context->tenant()->load('teacherProfile'));
    }
}
