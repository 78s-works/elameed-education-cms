<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Centers\Http\Requests\CenterSyncRequest;
use App\Modules\Centers\Services\CenterSyncService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * POST /teacher/centers/sync (M12) — the offline center app flushes its queued
 * attendance + redemption events. Applied idempotently; returns per-item results.
 */
class CenterSyncController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CenterSyncService $sync,
    ) {}

    public function __invoke(CenterSyncRequest $request): JsonResponse
    {
        $results = $this->sync->handle(
            $this->context->tenantOrFail()->getKey(),
            $request->validated('events'),
            $request->user()->getKey(),
        );

        return response()->json(['data' => $results]);
    }
}
