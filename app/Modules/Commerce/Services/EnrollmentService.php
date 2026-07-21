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
 * Access lives in the `enrollments` table. A grant is either whole-course
 * (`course_id`) or a single unit (`unit_id`, from a package). Course grants open
 * everything in the course (lessons + exams); unit grants open only that unit's
 * lessons — exams stay tied to a full-course enrollment.
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

        return $this->grant($tenantId, $userId, $source, $course->getKey(), null, $bundleId, $expiresAt);
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
            if ($item->item_type === BundleItem::TYPE_COURSE && $item->course_id !== null) {
                $this->grant($tenantId, $userId, $source, (int) $item->course_id, null, $bundle->getKey(), $expiresAt);
            } elseif ($item->item_type === BundleItem::TYPE_UNIT && $item->unit_id !== null) {
                $this->grant($tenantId, $userId, $source, null, (int) $item->unit_id, $bundle->getKey(), $expiresAt);
            }
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
     * free preview, its course is free, the user has a whole-course enrollment, OR
     * the user has a unit enrollment for the lesson's unit (a package that bundled
     * just that chapter).
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
                $q->where('course_id', $lesson->course_id);
                if ($lesson->unit_id !== null) {
                    $q->orWhere('unit_id', $lesson->unit_id);
                }
            })
            ->exists();
    }

    /**
     * Upsert an active enrollment for a course OR unit. Returns the existing active
     * grant if one is already present (so replays / repeat purchases don't stack).
     */
    private function grant(
        int $tenantId,
        int $userId,
        EnrollmentSource $source,
        ?int $courseId,
        ?int $unitId,
        ?int $bundleId,
        ?\DateTimeInterface $expiresAt,
    ): Enrollment {
        $existing = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->when($courseId !== null,
                fn ($q) => $q->where('course_id', $courseId),
                fn ($q) => $q->where('unit_id', $unitId),
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment = new Enrollment([
            'user_id' => $userId,
            'course_id' => $courseId,
            'unit_id' => $unitId,
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
