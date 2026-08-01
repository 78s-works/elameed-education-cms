<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\LessonAvailabilityService;
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
    public function __construct(
        private readonly LessonAvailabilityService $availability,
    ) {}

    /**
     * Grant a whole-course enrollment. `$bundleId` records the package it came
     * from, when the grant originates from a bundle purchase.
     */
    public function grantCourse(int $tenantId, int $userId, Course $course, EnrollmentSource $source, ?int $bundleId = null): Enrollment
    {
        $expiresAt = $course->access_days ? now()->addDays($course->access_days) : null;

        return $this->grant($tenantId, $userId, $source, $course->getKey(), null, null, null, $bundleId, $expiresAt);
    }

    /** Grant access to a single unit (doc 11 R7 — teacher can grant a unit). */
    public function grantUnit(int $tenantId, int $userId, Unit $unit, EnrollmentSource $source): Enrollment
    {
        return $this->grant($tenantId, $userId, $source, null, $unit->getKey(), null, null, null, null);
    }

    /**
     * Grant access to a single lesson (doc 11 R4 "pay lesson" + R7). Opens the
     * time-boxed availability window immediately so the "week" counts from the
     * grant/payment (decision D3); no-op window when the lesson is unlimited.
     */
    public function grantLesson(int $tenantId, int $userId, Lesson $lesson, EnrollmentSource $source): Enrollment
    {
        $enrollment = $this->grant($tenantId, $userId, $source, null, null, $lesson->getKey(), null, null, null);
        $this->availability->start($tenantId, $userId, $lesson);

        return $enrollment;
    }

    /** Grant access to a single exam (doc 11 R7 / decision D7). */
    public function grantExam(int $tenantId, int $userId, Exam $exam, EnrollmentSource $source): Enrollment
    {
        return $this->grant($tenantId, $userId, $source, null, null, null, $exam->getKey(), null, null);
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
                    ? $this->grant($tenantId, $userId, $source, (int) $item->course_id, null, null, null, $bundle->getKey(), $expiresAt)
                    : null,
                BundleItem::TYPE_UNIT => $item->unit_id !== null
                    ? $this->grant($tenantId, $userId, $source, null, (int) $item->unit_id, null, null, $bundle->getKey(), $expiresAt)
                    : null,
                BundleItem::TYPE_LESSON => $item->lesson_id !== null
                    ? $this->grant($tenantId, $userId, $source, null, null, (int) $item->lesson_id, null, $bundle->getKey(), $expiresAt)
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

        // A soft-deleted (or missing) parent course takes all of its lessons
        // offline, even for a student who still holds a grant (H3): the `course`
        // relation is null once the course is trashed, so serving the lesson would
        // leave access inconsistent with the now-hidden course.
        if ($lesson->course_id !== null && $course === null) {
            return false;
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
     * Does the user have access to this exam? A free_exam is open to any logged-in
     * student (no enrollment). Otherwise true when the exam's course is free, or the
     * user holds any grant covering it: the whole course, the exam's unit, the
     * exam's lesson, or a direct exam grant.
     */
    public function hasExamAccess(int $tenantId, int $userId, Exam $exam): bool
    {
        // Free exams bypass enrollment entirely (convention model).
        if ($exam->type === ExamType::FreeExam) {
            return true;
        }

        $course = $exam->course;
        if ($course !== null && $course->is_free) {
            return true;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->grantsAccess()
            ->where(function ($q) use ($exam): void {
                $q->where('course_id', $exam->course_id)
                    ->orWhere('exam_id', $exam->getKey());
                if ($exam->unit_id !== null) {
                    $q->orWhere('unit_id', $exam->unit_id);
                }
                if ($exam->lesson_id !== null) {
                    $q->orWhere('lesson_id', $exam->lesson_id);
                }
            })
            ->exists();
    }

    /**
     * Upsert an active enrollment for a course, unit, lesson, OR exam (exactly one
     * id is non-null). Returns the existing active grant if one is already present
     * (so replays / repeat purchases don't stack).
     */
    private function grant(
        int $tenantId,
        int $userId,
        EnrollmentSource $source,
        ?int $courseId,
        ?int $unitId,
        ?int $lessonId,
        ?int $examId,
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
            ->when($examId !== null, fn ($q) => $q->where('exam_id', $examId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment = new Enrollment([
            'user_id' => $userId,
            'course_id' => $courseId,
            'unit_id' => $unitId,
            'lesson_id' => $lessonId,
            'exam_id' => $examId,
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
