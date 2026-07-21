<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;

/**
 * Grants and checks content access. Takes an explicit tenant id so it works from
 * webhook contexts where no tenant is resolved from the host.
 *
 * Access lives in the `enrollments` table. A grant is whole-course (`course_id`),
 * a unit (`unit_id`), or a single lesson (`lesson_id`) — the last two come from a
 * package. Course grants open everything in the course (lessons + exams); unit
 * grants open that chapter's lessons; lesson grants open just that lesson. Exams
 * stay tied to a full-course enrollment.
 */
class EnrollmentService
{
    /**
     * Grant a whole-course enrollment. `$bundleId` records the package it came
     * from, when the grant originates from a bundle purchase.
     */
    public function grantCourse(int $tenantId, int $userId, Course $course, EnrollmentSource $source, ?int $bundleId = null): Enrollment
    {
        $expiresAt = $course->access_days ? now()->addDays($course->access_days) : null;

        return $this->grant($tenantId, $userId, $source, $course->getKey(), null, null, $bundleId, $expiresAt);
    }

    /**
     * Grant access to every item in a package. The package's own `access_days`
     * governs the window for all grants (null = lifetime). Idempotent per item.
     */
    public function grantBundle(int $tenantId, int $userId, Bundle $bundle, EnrollmentSource $source): void
    {
        $expiresAt = $bundle->access_days ? now()->addDays($bundle->access_days) : null;
        $bundle->loadMissing('items');

        foreach ($bundle->items as $item) {
            match ($item->item_type) {
                BundleItem::TYPE_COURSE => $item->course_id !== null
                    ? $this->grant($tenantId, $userId, $source, (int) $item->course_id, null, null, $bundle->getKey(), $expiresAt)
                    : null,
                BundleItem::TYPE_UNIT => $item->unit_id !== null
                    ? $this->grant($tenantId, $userId, $source, null, (int) $item->unit_id, null, $bundle->getKey(), $expiresAt)
                    : null,
                BundleItem::TYPE_LESSON => $item->lesson_id !== null
                    ? $this->grant($tenantId, $userId, $source, null, null, (int) $item->lesson_id, $bundle->getKey(), $expiresAt)
                    : null,
                default => null,
            };
        }
    }

    /** Does the user currently have access to the whole course? Free courses are open. */
    public function hasAccess(int $tenantId, int $userId, Course $course): bool
    {
        if ($course->is_free) {
            return true;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $course->getKey())
            ->grantsAccess()
            ->exists();
    }

    /**
     * Does the user have access to this specific lesson? True when the lesson is a
     * free preview, its course is free, OR the user holds any grant that covers it:
     * a whole-course enrollment, a unit enrollment for the lesson's unit, or a
     * lesson enrollment for this exact lesson (a package that bundled just it).
     */
    public function hasLessonAccess(int $tenantId, int $userId, Lesson $lesson): bool
    {
        if ($lesson->is_free_preview) {
            return true;
        }

        $course = $lesson->course;
        if ($course !== null && $course->is_free) {
            return true;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->grantsAccess()
            ->where(function ($q) use ($lesson): void {
                $q->where('course_id', $lesson->course_id)
                    ->orWhere('lesson_id', $lesson->getKey());
                if ($lesson->unit_id !== null) {
                    $q->orWhere('unit_id', $lesson->unit_id);
                }
            })
            ->exists();
    }

    /**
     * Upsert an active enrollment for a course, unit, OR lesson (exactly one id is
     * non-null). Returns the existing active grant if one is already present (so
     * replays / repeat purchases don't stack).
     */
    private function grant(
        int $tenantId,
        int $userId,
        EnrollmentSource $source,
        ?int $courseId,
        ?int $unitId,
        ?int $lessonId,
        ?int $bundleId,
        ?\DateTimeInterface $expiresAt,
    ): Enrollment {
        $existing = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->when($courseId !== null, fn ($q) => $q->where('course_id', $courseId))
            ->when($unitId !== null, fn ($q) => $q->where('unit_id', $unitId))
            ->when($lessonId !== null, fn ($q) => $q->where('lesson_id', $lessonId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment = new Enrollment([
            'user_id' => $userId,
            'course_id' => $courseId,
            'unit_id' => $unitId,
            'lesson_id' => $lessonId,
            'bundle_id' => $bundleId,
            'source' => $source->value,
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'status' => EnrollmentStatus::Active->value,
        ]);
        $enrollment->tenant_id = $tenantId;
        $enrollment->save();

        return $enrollment;
    }
}
