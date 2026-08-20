<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\EnrollStudentRequest;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * A teacher granting/revoking a student's access directly (no payment) — e.g.
 * offline/center students. A grant targets a single lesson, an exam, or a
 * recursive package (which fans out into per-lesson grants, B15). Manual
 * enrollments are source=manual. (`courses`/units/bundles retired — VD §7.)
 */
class StudentEnrollmentController
{
    use ManagesTenantStudents;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
    ) {}

    public function index(User $student): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $rows = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->with('lesson:id,title')
            ->latest('id')
            ->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'lesson_id' => $e->lesson_id,
                'lesson_title' => $e->lesson?->title,
                'exam_id' => $e->exam_id,
                'package_id' => $e->package_id,
                'source' => $e->source->value,
                'status' => $e->status->value,
                'starts_at' => $e->starts_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(EnrollStudentRequest $request, User $student): JsonResponse
    {
        $tenantId = (int) $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $type = (string) $request->validated('target_type');
        $target = $request->validated('target');
        $userId = (int) $student->getKey();

        // A package grant fans out into per-lesson rows — report the count, not one id.
        if ($type === 'package') {
            $package = $this->findPackage($target);
            $this->assertSameYearAsStudent($tenantId, $userId, $package->academic_year_id);
            $grants = $this->enrollments->grantPackage($tenantId, $userId, $package, EnrollmentSource::Manual);

            return response()->json(['data' => ['target_type' => 'package', 'granted' => $grants->count()]], 201);
        }

        $enrollment = match ($type) {
            'lesson' => $this->grantLesson($tenantId, $userId, $this->findLesson($target)),
            'exam' => $this->grantExam($tenantId, $userId, $this->findExam($target)),
        };

        return response()->json(['data' => [
            'id' => $enrollment->id,
            'target_type' => $type,
            'status' => $enrollment->status->value,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ]], 201);
    }

    private function grantLesson(int $tenantId, int $userId, Lesson $lesson): Enrollment
    {
        $this->assertSameYearAsStudent($tenantId, $userId, $lesson->academic_year_id);

        return $this->enrollments->grantLesson($tenantId, $userId, $lesson, EnrollmentSource::Manual);
    }

    private function grantExam(int $tenantId, int $userId, Exam $exam): Enrollment
    {
        $this->assertSameYearAsStudent($tenantId, $userId, $exam->academic_year_id);

        return $this->enrollments->grantExam($tenantId, $userId, $exam, EnrollmentSource::Manual);
    }

    /**
     * A student is PINNED to one academic year and every student-facing query is
     * scoped to it, so content from another year would be granted and then never
     * appear for them — a silent no-op. The target resolves against the TEACHER's
     * active year (the `academic-year` middleware), which is not necessarily the
     * student's, so refuse the mismatch with a message naming both years instead.
     */
    private function assertSameYearAsStudent(int $tenantId, int $userId, ?int $targetYearId): void
    {
        $studentYearId = StudentProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->value('academic_year_id');

        if ($targetYearId === null || $studentYearId === null || (int) $targetYearId === (int) $studentYearId) {
            return;
        }

        $names = AcademicYear::withoutGlobalScopes()
            ->whereIn('id', [$targetYearId, $studentYearId])
            ->pluck('name', 'id');

        throw ValidationException::withMessages([
            'target' => __('This content belongs to :target, but the student is in :student. Switch to that academic year to grant them content.', [
                'target' => $names[$targetYearId] ?? '#'.$targetYearId,
                'student' => $names[$studentYearId] ?? '#'.$studentYearId,
            ]),
        ]);
    }

    private function findLesson(?string $id): Lesson
    {
        $lesson = Lesson::query()->find((int) $id);
        abort_if($lesson === null, 404, 'Lesson not found in this academy.');

        return $lesson;
    }

    private function findPackage(?string $uuid): Package
    {
        $package = Package::query()->where('uuid', $uuid)->first();
        abort_if($package === null, 404, 'Package not found in this academy.');

        return $package;
    }

    private function findExam(?string $uuid): Exam
    {
        $exam = Exam::query()->where('uuid', $uuid)->first();
        abort_if($exam === null, 404, 'Exam not found in this academy.');

        return $exam;
    }

    public function destroy(User $student, int $enrollment): Response
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $row = Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $student->getKey())
            ->where('id', $enrollment)
            ->first();

        abort_if($row === null, 404, 'Enrollment not found.');

        $row->update(['status' => EnrollmentStatus::Cancelled->value]);

        return response()->noContent();
    }
}
