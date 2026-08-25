<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\AcademicYearRequest;
use App\Modules\Catalog\Http\Resources\AcademicYearResource;
use App\Modules\Catalog\Models\AcademicYear;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * /teacher/academic-years (VD change set) — the tenant's top-level content
 * containers. Tenant-scoped by BelongsToTenant + {academicYear:uuid} binding
 * (a cross-tenant uuid 404s). NOT behind the `academic-year` middleware — this
 * is where years are managed, so no year context is required or wanted.
 */
class AcademicYearController
{
    /**
     * The years the CALLER may work in. A year-scoped member (an assistant hired
     * for specific years) must not even be OFFERED another year: the panel builds
     * its year picker from this list, and pointing the X-Academic-Year header at an
     * unassigned year is rejected 403 `academic_year_out_of_scope` — which used to
     * surface as a silently empty page. An unscoped member (the academy owner) has
     * no assigned years and sees them all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assigned = $this->assignedYearIds($request);

        $years = AcademicYear::query()
            ->when($assigned !== [], fn ($q) => $q->whereIn('id', $assigned))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(20);

        return AcademicYearResource::collection($years);
    }

    /** @return array<int, int> the caller's assigned year ids, [] when unscoped */
    private function assignedYearIds(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->tenant();

        if ($user === null || $tenant === null) {
            return [];
        }

        $membership = $user->membershipFor($tenant);

        if ($membership === null) {
            return [];
        }

        return $membership->academicYears()
            ->pluck('academic_years.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function store(AcademicYearRequest $request): JsonResponse
    {
        $year = AcademicYear::create($request->validated()); // BelongsToTenant fills tenant_id

        return (new AcademicYearResource($year))->response()->setStatusCode(201);
    }

    public function show(AcademicYear $academicYear): AcademicYearResource
    {
        return new AcademicYearResource($academicYear);
    }

    public function update(AcademicYearRequest $request, AcademicYear $academicYear): AcademicYearResource
    {
        $academicYear->update($request->validated());

        return new AcademicYearResource($academicYear);
    }

    public function destroy(Request $request, AcademicYear $academicYear): Response
    {
        // Typed confirmation: the client must echo the exact name back. Guards a
        // destructive delete (later phases cascade content under the year).
        if ($request->input('confirm_name') !== $academicYear->name) {
            throw ValidationException::withMessages([
                'confirm_name' => 'The confirmation name does not match the academic year name.',
            ]);
        }

        // Students are PINNED to a year (student_profiles.academic_year_id is NOT
        // NULL and RESTRICTs on delete). Refuse the delete with a clear message
        // instead of letting the FK raise a 500 — the teacher must move those
        // students to another year first. Historically this FK was nullOnDelete,
        // which silently unpinned them and locked every one of them out of the
        // panel (LoginAction: `academic_year_required`).
        $pinned = StudentProfile::query()->where('academic_year_id', $academicYear->getKey())->count();

        if ($pinned > 0) {
            throw ValidationException::withMessages([
                'academic_year' => __('This academic year still has :count student(s) assigned to it. Move them to another year before deleting it.', ['count' => $pinned]),
            ]);
        }

        DB::transaction(fn () => $academicYear->delete());

        return response()->noContent();
    }
}
