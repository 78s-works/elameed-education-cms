<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Catalog\Models\Course;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\EnrollmentStatus;
use App\Modules\Commerce\Models\Enrollment;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Identity\Http\Controllers\Teacher\Concerns\ManagesTenantStudents;
use App\Modules\Identity\Http\Requests\EnrollStudentRequest;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A teacher granting/revoking a student's access directly (no payment) — e.g.
 * offline/center students. A grant can target a whole course, a unit, a single
 * lesson, or a single exam (doc 11 R7). Manual enrollments are source=manual.
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
            ->with('course:id,uuid,title')
            ->latest('id')
            ->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'course' => $e->course?->uuid,
                'course_title' => $e->course?->title,
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

        $type = $request->validated('target_type') ?? 'course';
        $target = $request->validated('target') ?? $request->validated('course');
        $userId = (int) $student->getKey();

        $enrollment = match ($type) {
            'unit' => $this->enrollments->grantUnit($tenantId, $userId, $this->findUnit($target), EnrollmentSource::Manual),
            'lesson' => $this->enrollments->grantLesson($tenantId, $userId, $this->findLesson($target), EnrollmentSource::Manual),
            'exam' => $this->enrollments->grantExam($tenantId, $userId, $this->findExam($target), EnrollmentSource::Manual),
            default => $this->enrollments->grantCourse($tenantId, $userId, $this->findCourse($target), EnrollmentSource::Manual),
        };

        return response()->json(['data' => [
            'id' => $enrollment->id,
            'target_type' => $type,
            'status' => $enrollment->status->value,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ]], 201);
    }

    private function findCourse(?string $uuid): Course
    {
        $course = Course::query()->where('uuid', $uuid)->first();
        abort_if($course === null, 404, 'Course not found in this academy.');

        return $course;
    }

    private function findUnit(?string $id): Unit
    {
        $unit = Unit::query()->find((int) $id);
        abort_if($unit === null, 404, 'Unit not found in this academy.');

        return $unit;
    }

    private function findLesson(?string $id): Lesson
    {
        $lesson = Lesson::query()->find((int) $id);
        abort_if($lesson === null, 404, 'Lesson not found in this academy.');

        return $lesson;
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
