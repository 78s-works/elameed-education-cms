<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Lesson;

/**
 * Lesson progression gate (convention model) — decides whether a student may OPEN
 * a lesson.
 *
 * Two rules, automatic and unconfigurable:
 *   - A staff-granted access override (ContentAccessOverride) on the lesson opens
 *     it outright.
 *   - Sequential package unlock (B14 / VD R5): a lesson bought as part of a
 *     package stays locked until the previous lesson in that package's ordered
 *     sequence is completed. No-op for lessons not sourced from a package.
 *
 * (The old unit-scoped "previous lesson quiz+homework" gate retired with the
 * courses/units model — VD §7.) Exams themselves are never locked — this gate only
 * sequences lesson (video) content. Solution videos within a lesson are gated
 * separately by ContentUnlockService.
 *
 * Access-critical: explicit tenant id, queries run withoutGlobalScopes
 * (mirrors EnrollmentService / ContentUnlockService).
 */
class LessonProgressionService
{
    public function __construct(
        private readonly ContentAccessOverrideService $overrides,
        private readonly SequentialUnlockService $sequential,
    ) {}

    /**
     * @return string|null null = the lesson may be opened; otherwise a machine lock
     *                     reason (prev_lesson_incomplete).
     */
    public function progressionLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        if ($lesson->is_free_preview) {
            return null;
        }

        // A staff-granted override on this lesson opens it outright.
        if ($this->overrides->hasActiveForLesson($tenantId, $userId, $lesson)) {
            return null;
        }

        // Sequential package unlock (B14 / VD R5): a lesson bought as part of a
        // package stays locked until the PREVIOUS lesson in that package's ordered
        // sequence is completed. No-op for lessons not sourced from a package.
        return $this->sequential->sequenceLock($tenantId, $userId, $lesson);
    }

    /** Convenience boolean. */
    public function canOpen(int $tenantId, int $userId, Lesson $lesson): bool
    {
        return $this->progressionLock($tenantId, $userId, $lesson) === null;
    }
}
