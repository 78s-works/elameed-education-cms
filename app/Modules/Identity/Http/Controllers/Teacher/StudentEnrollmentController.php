<?php

namespace App\Modules\Identity\Http\Controllers\Teacher;

use App\Models\User;
use App\Modules\Catalog\Models\Course;
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
 * A teacher granting/revoking a student's course access directly (no payment) —
 * e.g. offline/center students. Manual enrollments are marked source=manual.
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
        $tenantId = $this->context->tenantOrFail()->getKey();
        $this->membershipOrFail($tenantId, $student);

        $course = Course::query()->where('uuid', $request->validated('course'))->first();
        abort_if($course === null, 404, 'Course not found in this academy.');

        $enrollment = $this->enrollments->grantCourse($tenantId, $student->getKey(), $course, EnrollmentSource::Manual);

        return response()->json(['data' => [
            'id' => $enrollment->id,
            'course' => $course->uuid,
            'status' => $enrollment->status->value,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ]], 201);
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
