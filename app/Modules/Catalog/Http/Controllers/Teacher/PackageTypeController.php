<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\PackageTypeRequest;
use App\Modules\Catalog\Http\Resources\PackageTypeResource;
use App\Modules\Catalog\Models\PackageType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/package-types (B27) — teacher-managed content-package categories.
 * Year-scoped by the `academic-year` middleware + the BelongsToAcademicYear
 * scope, so a type from another year (or tenant) is simply not found → 404.
 * Bound by uuid, mirroring AcademicYearController. Deleting a type nulls its
 * packages' package_type_id (the DB FK is nullOnDelete); the packages survive.
 */
class PackageTypeController
{
    public function index(): AnonymousResourceCollection
    {
        $types = PackageType::query()->orderBy('sort_order')->orderBy('id')->paginate(20);

        return PackageTypeResource::collection($types);
    }

    public function store(PackageTypeRequest $request): JsonResponse
    {
        // tenant_id + academic_year_id are auto-filled by the model traits.
        $type = PackageType::create($request->validated());

        return (new PackageTypeResource($type))->response()->setStatusCode(201);
    }

    public function show(PackageType $packageType): PackageTypeResource
    {
        return new PackageTypeResource($packageType);
    }

    public function update(PackageTypeRequest $request, PackageType $packageType): PackageTypeResource
    {
        $packageType->update($request->validated());

        return new PackageTypeResource($packageType);
    }

    public function destroy(PackageType $packageType): Response
    {
        // FK nullOnDelete: any packages carrying this type keep, with package_type_id nulled.
        $packageType->delete();

        return response()->noContent();
    }
}
