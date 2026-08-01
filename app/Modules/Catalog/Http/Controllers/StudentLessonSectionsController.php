<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\LessonSectionResource;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Services\ContentUnlockService;
use App\Modules\Catalog\Services\LessonProgressionService;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /lessons/{lesson}/sections — the student's view of a lesson's typed
 * content sections, each stamped with its computed `locked` state from the
 * mandatory "Content Dependencies & Unlock Rules". Optional dependencies never
 * lock; they surface for the client to display.
 */
class StudentLessonSectionsController
{
    public function __construct(
        private readonly ContentUnlockService $unlock,
        private readonly EnrollmentService $enrollments,
        private readonly LessonProgressionService $progression,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $userId = (int) $request->user()->getKey();

        if (! $lesson->is_free_preview && ! $this->enrollments->hasLessonAccess($tenantId, $userId, $lesson)) {
            abort(403, 'You do not have access to this lesson.');
        }

        // Progression gate (doc 11 R5): the previous lesson/unit must be cleared
        // before this lesson opens. 423 Locked carries the machine reason.
        $lock = $this->progression->progressionLock($tenantId, $userId, $lesson);
        if ($lock !== null) {
            throw new HttpException(423, $lock);
        }

        $sections = $lesson->sections()->ordered()->with(['mediaAsset', 'exam', 'dependencies'])->get();
        $lockMap = $this->unlock->lockMap($tenantId, $userId, $lesson);

        foreach ($sections as $section) {
            $section->setAttribute('locked', $lockMap[(int) $section->id] ?? false);
        }

        return LessonSectionResource::collection($sections);
    }
}
