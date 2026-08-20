<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Assessment\Enums\ExamType;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\LessonAvailabilityService;
use App\Modules\Catalog\Services\PackageItemService;
use App\Modules\Catalog\Services\SequentialUnlockService;
use App\Modules\Catalog\Services\StudentPartVisibility;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Grants and checks content access. Takes an explicit tenant id so it works from
 * webhook contexts where no tenant is resolved from the host.
 *
 * Access lives in the `enrollments` table. A grant targets a single lesson
 * (`lesson_id`) or a single exam (`exam_id`). A package grant is NOT stored as one
 * row: it fans out (depth-first) into a per-lesson enrollment for every descendant
 * lesson, each tagged with the source `package_id` (B15 / VD LP-D2). Access is
 * always resolved per-lesson (or per-exam) — the `courses` entity was retired
 * (VD §7), so there is no whole-course grant.
 */
class EnrollmentService
{
    public function __construct(
        private readonly LessonAvailabilityService $availability,
        private readonly PackageItemService $packageItems,
        private readonly SequentialUnlockService $sequential,
        private readonly StudentPartVisibility $studyMode,
    ) {}

    /**
     * Channel scope (VD §7): a single-channel student (center/online) may only
     * reach content on their own channel plus `both`; a hybrid (`both`) student —
     * and any student with no study_mode set (legacy) — reaches every channel.
     * `both` content is visible to everyone, and content with no access_mode
     * (null) carries no channel restriction. Reuses {@see AccessMode::isVisibleTo}
     * so the play gate matches the catalogue filter and checkout guard exactly.
     */
    private function channelAllows(int $tenantId, int $userId, ?AccessMode $contentMode): bool
    {
        return $contentMode === null
            || $contentMode->isVisibleTo($this->studyMode->studyModeFor($tenantId, $userId));
    }

    /**
     * Grant access to a single lesson (doc 11 R4 "pay lesson" + R7). Opens the
     * time-boxed availability window immediately so the "week" counts from the
     * grant/payment (decision D3); no-op window when the lesson is unlimited.
     * `$packageId` records the package the grant fanned out from, when it did (B15).
     */
    public function grantLesson(int $tenantId, int $userId, Lesson $lesson, EnrollmentSource $source, ?int $packageId = null): Enrollment
    {
        $enrollment = $this->grant($tenantId, $userId, $source, $lesson->getKey(), null, $packageId, null);
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
        return $this->grant($tenantId, $userId, $source, null, $exam->getKey(), null, null);
    }

    /**
     * Does the user have access to this recursive package? True when they hold an
     * access-granting enrollment for at least one of the package's descendant
     * lessons (the fan-out grants per-lesson rows, B15) — enough to say they engaged
     * with it, e.g. to leave a review. An empty package grants nothing → no access.
     */
    public function hasPackageAccess(int $tenantId, int $userId, Package $package): bool
    {
        $lessonIds = $this->packageItems->descendantLessonIds($package);

        if ($lessonIds->isEmpty()) {
            return false;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds->all())
            ->grantsAccess()
            ->exists();
    }

    /**
     * Does the user hold an access-granting enrollment for this lesson — bought
     * alone, fanned out from a package, redeemed from a code, or granted by hand
     * from the teacher panel? Channel-agnostic and preview-agnostic: this is the
     * raw "was access given to them" question.
     */
    public function hasLessonGrant(int $tenantId, int $userId, Lesson $lesson): bool
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->grantsAccess()
            ->where('lesson_id', $lesson->getKey())
            ->exists();
    }

    /**
     * Does the user have access to this specific lesson?
     *
     * An explicit grant WINS over the channel rule. The channel scope (VD §7) is a
     * discovery/acquisition rule — it is what stops a center student from browsing
     * and buying online content, and it is enforced where that happens
     * (PublicCatalogController + CheckoutService). By the time an enrollment row
     * exists someone has already decided this student may have this lesson: a
     * teacher granting it by hand, a redeemed code, a center check-in, or a package
     * fan-out. Re-applying the channel filter here voided that decision — a center
     * student granted an online lesson saw it in /me/lessons and then got a 403 on
     * opening it, with nothing to explain why.
     *
     * With no grant the channel rule still applies, so a free preview on the other
     * channel stays out of reach.
     */
    public function hasLessonAccess(int $tenantId, int $userId, Lesson $lesson): bool
    {
        if ($this->hasLessonGrant($tenantId, $userId, $lesson)) {
            return true;
        }

        return $lesson->is_free_preview
            && $this->channelAllows($tenantId, $userId, $lesson->access_mode);
    }

    /**
     * Does the user have access to this exam? A free_exam is open to any logged-in
     * student (no enrollment). Otherwise true when the user holds a grant covering
     * it: a direct exam grant, or the exam's lesson.
     */
    public function hasExamAccess(int $tenantId, int $userId, Exam $exam): bool
    {
        // Free exams — and standalone "free homework" (homework with no lesson) —
        // bypass enrollment entirely (open to any logged-in student).
        if ($exam->type === ExamType::FreeExam) {
            return true;
        }

        if ($exam->type === ExamType::Homework && $exam->lesson_id === null) {
            return true;
        }

        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->grantsAccess()
            ->where(function ($q) use ($exam): void {
                $q->where('exam_id', $exam->getKey());
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
            : $this->grant($tenantId, $userId, $source, $lesson->getKey(), null, $packageId, null);
    }

    /**
     * Upsert an active enrollment for a lesson OR exam (exactly one id is non-null).
     * Returns the existing active grant if one is already present (so replays /
     * repeat purchases don't stack). `$packageId` is provenance only — carried on a
     * lesson row that fanned out from a package, never a match key.
     */
    private function grant(
        int $tenantId,
        int $userId,
        EnrollmentSource $source,
        ?int $lessonId,
        ?int $examId,
        ?int $packageId,
        ?\DateTimeInterface $expiresAt,
    ): Enrollment {
        $existing = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->when($lessonId !== null, fn ($q) => $q->where('lesson_id', $lessonId))
            ->when($examId !== null, fn ($q) => $q->where('exam_id', $examId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $enrollment = new Enrollment([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'exam_id' => $examId,
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
