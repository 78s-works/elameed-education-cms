<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\AcademicYearRequest;
use App\Modules\Catalog\Http\Resources\AcademicYearResource;
use App\Modules\Catalog\Models\AcademicYear;
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
    public function index(): AnonymousResourceCollection
    {
        $years = AcademicYear::query()->orderBy('sort_order')->orderBy('id')->paginate(20);

        return AcademicYearResource::collection($years);
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

        DB::transaction(fn () => $academicYear->delete());

        return response()->noContent();
    }
}
