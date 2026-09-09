<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Services\StudentResultsQuery;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/results — the calling student's own grade history: online exam and
 * homework attempts plus paper (in-center) grades, newest first, with an average.
 *
 * Server-owned so the dashboard and the Exams screen show the same history on
 * every device. Tenant- and year-scoped: the models carry both global scopes and
 * the academic-year middleware pins a student to their own year, so another
 * academy's (or another year's) grades cannot appear here.
 */
class StudentResultsController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentResultsQuery $results,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payload = $this->results->for(
            (int) $this->context->tenantOrFail()->getKey(),
            (int) $request->user()->getKey(),
            crossYear: false,
            limit: (int) $request->integer('limit', 100),
        );

        return response()->json($payload);
    }
}
