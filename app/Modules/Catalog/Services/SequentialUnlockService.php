<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\LessonAccessWindow;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Engagement\Models\LessonProgress;
use Illuminate\Support\Collection;

/**
 * Sequential bundle/package unlock engine (B14 / VD R5, doc 12 §4.2).
 *
 * Buying a package fans out into per-lesson access grants (LP-D2, EnrollmentService),
 * but the LESSONS open one at a time, in package order:
 *   • on purchase — only the FIRST lesson's window opens (see {@see openFirst});
 *   • thereafter  — the next lesson's window opens ONLY when the previous lesson is
 *     COMPLETED (watched to completion → `lesson_progress.completed_at`, VD-D3:
 *     expiry alone never advances), driven by the {@see \App\Modules\Catalog\Events\LessonCompleted}
 *     event ({@see advanceAfter}).
 *
 * Each opened lesson gets its OWN independent window (7-day default) starting from
 * the moment it opens, never a shared bundle timer — {@see LessonAvailabilityService::start}
 * stamps `expires_at = now + availability_days`.
 *
 * The order walked is `package_items.sort_order`, depth-first through sub-packages
 * ({@see PackageItemService::orderedLessonIds}). {@see sequenceLock} is the content
 * gate the progression service consults so a student can't jump ahead of the
 * sequence by hitting playback/sections directly.
 *
 * Access-critical: explicit tenant id, queries run withoutGlobalScopes (mirrors
 * EnrollmentService / LessonProgressionService).
 */
class SequentialUnlockService
{
    public function __construct(
        private readonly PackageItemService $packageItems,
        private readonly LessonAvailabilityService $availability,
    ) {}

    /**
     * On package purchase: open the window of the FIRST lesson in the package's
     * ordered sequence and no others. Idempotent; returns null when the package
     * has no lessons or the first lesson is unlimited (no window).
     */
    public function openFirst(int $tenantId, int $userId, Package $package): ?LessonAccessWindow
    {
        $first = $this->packageItems->orderedLessonIds($package)->first();
        if ($first === null) {
            return null;
        }

        return $this->openLessonWindow($tenantId, $userId, (int) $first);
    }

    /**
     * Completion of `$completedLessonId` opens the NEXT lesson's window in every
     * package the student bought that contains it. Defensive: does nothing unless
     * the lesson is genuinely completed (VD-D3). Idempotent (a re-fired event just
     * finds the already-open next window).
     *
     * @return array<int, LessonAccessWindow> the windows opened (existing or new)
     */
    public function advanceAfter(int $tenantId, int $userId, int $completedLessonId): array
    {
        if (! $this->isLessonCompleted($tenantId, $userId, $completedLessonId)) {
            return [];
        }

        $opened = [];
        foreach ($this->purchasedPackagesContaining($tenantId, $userId, $completedLessonId) as $package) {
            $next = $this->lessonAfter($package, $completedLessonId);
            if ($next === null) {
                continue; // last lesson in this package
            }

            $window = $this->openLessonWindow($tenantId, $userId, $next);
            if ($window !== null) {
                $opened[] = $window;
            }
        }

        return $opened;
    }

    /**
     * Sequence content-gate (consulted by {@see LessonProgressionService}). A lesson
     * reached through a package purchase stays locked until the PREVIOUS lesson in
     * that package's ordered sequence is completed. Returns a machine lock reason or
     * null when the lesson may open:
     *   • null — the lesson wasn't bought via a package (bought standalone / course
     *     grant / free preview), OR it's the first in its package, OR its predecessor
     *     is completed, OR the student also holds a non-package grant covering it;
     *   • 'prev_lesson_incomplete' — every covering package still has an unfinished
     *     predecessor.
     */
    public function sequenceLock(int $tenantId, int $userId, Lesson $lesson): ?string
    {
        $packages = $this->purchasedPackagesContaining($tenantId, $userId, (int) $lesson->getKey());
        if ($packages->isEmpty()) {
            return null; // not a package-sourced lesson — nothing to sequence
        }

        // A non-package grant covering this lesson (bought alone, or a whole-course
        // grant) opens it outright, regardless of any package sequence.
        if ($this->hasDirectGrant($tenantId, $userId, $lesson)) {
            return null;
        }

        // Open if ANY bought package has it first or its predecessor completed.
        foreach ($packages as $package) {
            $prev = $this->lessonBefore($package, (int) $lesson->getKey());
            if ($prev === null || $this->isLessonCompleted($tenantId, $userId, $prev)) {
                return null;
            }
        }

        return 'prev_lesson_incomplete';
    }

    // — internals ————————————————————————————————————————————————

    /** Open (or return the running) window for a lesson; null when unlimited/missing. */
    private function openLessonWindow(int $tenantId, int $userId, int $lessonId): ?LessonAccessWindow
    {
        $lesson = Lesson::withoutGlobalScopes()->find($lessonId);

        return $lesson === null ? null : $this->availability->start($tenantId, $userId, $lesson);
    }

    /** The lesson immediately BEFORE `$lessonId` in the package sequence, or null if first/absent. */
    private function lessonBefore(Package $package, int $lessonId): ?int
    {
        $ordered = $this->packageItems->orderedLessonIds($package)->all();
        $pos = array_search($lessonId, $ordered, true);

        return ($pos === false || $pos === 0) ? null : (int) $ordered[$pos - 1];
    }

    /** The lesson immediately AFTER `$lessonId` in the package sequence, or null if last/absent. */
    private function lessonAfter(Package $package, int $lessonId): ?int
    {
        $ordered = $this->packageItems->orderedLessonIds($package)->all();
        $pos = array_search($lessonId, $ordered, true);

        return ($pos === false || $pos === count($ordered) - 1) ? null : (int) $ordered[$pos + 1];
    }

    /**
     * The packages the student actively bought (a `package_id`-tagged, access-granting
     * enrollment) whose ordered sequence contains `$lessonId`.
     *
     * @return Collection<int, Package>
     */
    private function purchasedPackagesContaining(int $tenantId, int $userId, int $lessonId): Collection
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->whereNotNull('package_id')
            ->grantsAccess()
            ->pluck('package_id')
            ->unique()
            ->map(fn ($id) => Package::withoutGlobalScopes()->find($id))
            ->filter()
            ->values();
    }

    /** A non-package grant (standalone lesson buy, or whole-course) that covers the lesson. */
    private function hasDirectGrant(int $tenantId, int $userId, Lesson $lesson): bool
    {
        $base = fn () => Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('package_id')
            ->grantsAccess();

        if ($base()->where('lesson_id', $lesson->getKey())->exists()) {
            return true;
        }

        return $lesson->course_id !== null
            && $base()->where('course_id', $lesson->course_id)->exists();
    }

    /** Has the student watched this lesson to completion (`lesson_progress.completed_at`)? */
    private function isLessonCompleted(int $tenantId, int $userId, int $lessonId): bool
    {
        return LessonProgress::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('lesson_id', $lessonId)
            ->whereNotNull('completed_at')
            ->exists();
    }
}
