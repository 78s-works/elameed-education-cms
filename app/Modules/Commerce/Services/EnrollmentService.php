<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Services\SequentialUnlockService;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Grants and checks content access. Takes an explicit tenant id so it works from
 * webhook contexts where no tenant is resolved from the host.
 *
 * Access lives in the `enrollments` table. A grant targets a whole course
 * (`course_id`), a single lesson (`lesson_id`), a single exam (`exam_id`), or a
 * recursive package. A package grant is NOT stored as one row: it fans out
 * (depth-first) into a per-lesson enrollment for every descendant lesson, each
 * tagged with the source `package_id` (B15 / VD LP-D2). Course grants open
 * everything in the course (lessons + exams); lesson grants open just that lesson.
 * (`unit_id` / `bundle_id` are dormant columns — Unit + Bundle retired, VD §7.)
 */
class EnrollmentService
{
    public function __construct(
        private readonly LessonAvailabilityService $availability,
        private readonly PackageItemService $packageItems,
        private readonly SequentialUnlockService $sequential,
    ) {}

    /**
     * Grant a whole-course enrollment. `$bundleId` records the package it came
     * from, when the grant originates from a bundle purchase.
     */
    public function grantCourse(int $tenantId, int $userId, Course $course, EnrollmentSource $source, ?int $bundleId = null): Enrollment
    {
        $expiresAt = $course->access_days ? now()->addDays($course->access_days) : null;

        return $this->grant($tenantId, $userId, $source, $course->getKey(), null, null, null, $bundleId, null, $expiresAt);
    }

    /**
     * Grant access to a single lesson (doc 11 R4 "pay lesson" + R7). Opens the
     * time-boxed availability window immediately so the "week" counts from the
     * grant/payment (decision D3); no-op window when the lesson is unlimited.
     * `$packageId` records the package the grant fanned out from, when it did (B15).
     */
    public function grantLesson(int $tenantId, int $userId, Lesson $lesson, EnrollmentSource $source, ?int $packageId = null): Enrollment
    {
        $enrollment = $this->grant($tenantId, $userId, $source, null, null, $lesson->getKey(), null, null, $packageId, null);
        $this->availability->start($tenantId, $userId, $lesson);

        return $enrollment;
    }

    /**
     * Grant a recursive package (B15 / VD LP-D2). Fans the purchase out depth-first
     * into a per-lesson enrollment for every descendant lesson — the package's own
     * lessons plus every lesson inside every sub-package, nested to any depth — each
     * tagged with this `$package`'s id as provenance. Idempotent per lesson: a
     * lesson already granted (bought alone, or shared by another package) is reused,
     * never duplicated, so a re-buy or partial overlap adds only the missing lessons.
     * No package-level access row is written; access is always resolved per-lesson.
     *
     * Sequential unlock (B14 / VD R5): the fan-out grants ACCESS to every lesson but
     * does NOT open their windows. Only the FIRST lesson's window opens now; the
     * rest open one at a time as each previous lesson is completed
     * ({@see SequentialUnlockService}).
     *
     * @return Collection<int, Enrollment> the per-lesson grants (existing + new)
     */
    public function grantPackage(int $tenantId, int $userId, Package $package, EnrollmentSource $source): Collection
    {
        $grants = $this->packageItems->descendantLessonIds($package)
            ->map(fn (int $lessonId): ?Enrollment => $this->grantPackageLesson($tenantId, $userId, $lessonId, $package->getKey(), $source))
            ->filter()
            ->values();

        $this->sequential->openFirst($tenantId, $userId, $package);

        return $grants;
    }

    /** Grant access to a single exam (doc 11 R7 / decision D7). */
    public function grantExam(int $tenantId, int $userId, Exam $exam, EnrollmentSource $source): Enrollment
    {
        return $this->grant($tenantId, $userId, $source, null, null, null, $exam->getKey(), null, null, null);
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
     * One leg of a package fan-out: resolve the descendant lesson (scope-free, so it
     * works from a webhook where no tenant/year is resolved) and grant ACCESS only —
     * the availability window is NOT opened here (unlike a standalone lesson buy).
     * The sequential-unlock engine opens windows one at a time (B14). Skips a lesson
     * that vanished mid-flight.
     */
    private function grantPackageLesson(int $tenantId, int $userId, int $lessonId, int $packageId, EnrollmentSource $source): ?Enrollment
    {
        $lesson = Lesson::withoutGlobalScopes()->find($lessonId);

        return $lesson === null
            ? null
            : $this->grant($tenantId, $userId, $source, null, null, $lesson->getKey(), null, null, $packageId, null);
    }

    /**
     * Upsert an active enrollment for a course, unit, lesson, OR exam (exactly one
     * id is non-null). Returns the existing active grant if one is already present
     * (so replays / repeat purchases don't stack). `$packageId` is provenance only
     * — carried on a lesson row that fanned out from a package, never a match key.
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
        ?int $packageId,
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
            'package_id' => $packageId,
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
