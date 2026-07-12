<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Services\LandingResolver;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /tenant/landing — 🌐 Public. Fully-resolved landing for the SPA
 * (LANDING_CONTRACT_V2.md): layout + nav + sections, with dynamic sections
 * resolved to real items. No auth required; if a bearer token IS present,
 * resolved course items carry `enrolled` for that student.
 */
class TenantLandingController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LandingResolver $resolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();

        return response()->json([
            'data' => $this->resolver->resolve(
                $tenant->getKey(),
                $tenant->teacherProfile,
                $request->user('sanctum'), // optional auth → powers `enrolled`
            ),
        ]);
    }
}
