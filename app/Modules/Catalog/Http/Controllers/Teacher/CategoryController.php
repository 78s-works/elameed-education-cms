<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\CourseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/categories (M04) — the teacher's course taxonomy. Tenant-scoped by
 * the BelongsToTenant global scope + route-model binding.
 */
class CategoryController
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            CourseCategory::query()->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = CourseCategory::create($request->validated());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, CourseCategory $category): CategoryResource
    {
        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(CourseCategory $category): Response
    {
        $category->delete();

        return response()->noContent();
    }
}
