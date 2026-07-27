<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Billing\Services\PlanLimitGuard;
use App\Modules\Catalog\Http\Requests\CourseRequest;
use App\Modules\Catalog\Http\Resources\CourseResource;
use App\Modules\Catalog\Models\Course;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/courses (M04, FR-M04-01..05). The teacher sees ALL their courses
 * regardless of visibility; tenant isolation is via BelongsToTenant + binding
 * ({course:uuid}, so a cross-tenant uuid 404s).
 */
class CourseController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitGuard $limits,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $courses = Course::query()->with('category')->latest()->paginate(20);

        return CourseResource::collection($courses);
    }

    public function store(CourseRequest $request): JsonResponse
    {
        // Subscription-package ceiling (FR-M03-02): reject when the plan's course
        // quota is exhausted, before creating anything.
        $this->limits->ensure($this->context->tenantOrFail()->getKey(), 'max_courses');

        $data = $request->validated();

        $course = new Course($data);
        $course->slug = Course::makeUniqueSlug($data['title']);
        $course->save(); // BelongsToTenant fills tenant_id

        return (new CourseResource($course))->response()->setStatusCode(201);
    }

    public function show(Course $course): CourseResource
    {
        return new CourseResource($course->load('category'));
    }

    public function update(CourseRequest $request, Course $course): CourseResource
    {
        // Slug stays stable across updates so public URLs don't break.
        $course->fill($request->validated())->save();

        return new CourseResource($course->load('category'));
    }

    public function destroy(Course $course): Response
    {
        $course->delete(); // soft delete

        return response()->noContent();
    }
}
