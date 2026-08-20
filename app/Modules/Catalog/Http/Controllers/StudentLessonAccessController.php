<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\LessonExtensionRequestResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Catalog\Services\LessonProgressionService;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Student lesson-access + countdown ("Lesson Availability & Extension Requests"
 * + "Lesson Countdown Timer"):
 *   POST /lessons/{lesson}/start              → confirm + open the window
 *   GET  /lessons/{lesson}/access             → remaining time for the timer
 *   POST /lessons/{lesson}/reopen             → auto self-reopen (VD R3/R4)
 *   POST /lessons/{lesson}/extension-request  → ask staff for more time after cap
 *
 * {lesson} binds by id and is tenant-scoped.
 */
class StudentLessonAccessController
{
    public function __construct(
        private readonly LessonAvailabilityService $availability,
        private readonly EnrollmentService $enrollments,
        private readonly LessonProgressionService $progression,
        private readonly TenantContext $context,
    ) {}

    public function start(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $user = $request->user();
        $this->assertLessonAccess($tenantId, $user->getKey(), $lesson);

        $lock = $this->progression->progressionLock($tenantId, (int) $user->getKey(), $lesson);
        if ($lock !== null) {
            throw new HttpException(423, $lock);
        }

        $window = $this->availability->start($tenantId, (int) $user->getKey(), $lesson);

        return response()->json(['data' => $this->windowPayload($lesson, $window)]);
    }

    public function access(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $user = $request->user();
        $this->assertLessonAccess($tenantId, $user->getKey(), $lesson);

        $window = $this->availability->windowFor($tenantId, (int) $user->getKey(), $lesson);

        return response()->json(['data' => $this->windowPayload($lesson, $window)]);
    }

    /**
     * Auto self-reopen (VD R3/R4): extend an expired/locked window by 24h with no
     * staff approval, up to the lesson's `self_reopen_limit`. 409
     * `reopen_limit_reached` once the auto budget is spent — the student then uses
     * `extension-request` (staff approval) below.
     */
    public function reopen(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $user = $request->user();
        $this->assertLessonAccess($tenantId, $user->getKey(), $lesson);

        $window = $this->availability->selfReopen($tenantId, (int) $user->getKey(), $lesson);

        return response()->json(['data' => $this->windowPayload($lesson, $window)]);
    }

    public function requestExtension(Request $request, Lesson $lesson): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $user = $request->user();
        $this->assertLessonAccess($tenantId, $user->getKey(), $lesson);

        $extension = $this->availability->requestExtension($tenantId, (int) $user->getKey(), $lesson);

        return (new LessonExtensionRequestResource($extension))->response()->setStatusCode(201);
    }

    private function assertLessonAccess(int $tenantId, int|string $userId, Lesson $lesson): void
    {
        if (! $this->enrollments->hasLessonAccess($tenantId, (int) $userId, $lesson)) {
            abort(403, 'You do not have access to this lesson.');
        }
    }

    private function windowPayload(Lesson $lesson, ?LessonAccessWindow $window): array
    {
        return [
            'lesson_id' => $lesson->id,
            'has_window' => $lesson->hasAvailabilityWindow(),
            'availability_days' => $lesson->availability_days,
            'max_extensions' => (int) $lesson->max_extensions,
            'extension_hours' => (int) $lesson->extension_hours,
            'started' => $window !== null,
            'started_at' => $window?->started_at?->toIso8601String(),
            'expires_at' => $window?->expires_at?->toIso8601String(),
            'remaining_sec' => $window?->remainingSeconds(),
            'locked' => $window?->isLocked() ?? false,
            'extensions_used' => (int) ($window?->extensions_used ?? 0),
            // Auto self-reopen budget (VD R3/R4). `can_self_reopen` drives the
            // student "Reopen (24h)" button: shown only on a locked window with
            // auto-budget left; past it the student falls back to a staff request.
            'self_reopen_limit' => (int) $lesson->self_reopen_limit,
            'self_reopens_remaining' => max(0, (int) $lesson->self_reopen_limit - (int) ($window?->extensions_used ?? 0)),
            'can_self_reopen' => ($window?->isLocked() ?? false)
                && (int) ($window?->extensions_used ?? 0) < (int) $lesson->self_reopen_limit,
        ];
    }
}
