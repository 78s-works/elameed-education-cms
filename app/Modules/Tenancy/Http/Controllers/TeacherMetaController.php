<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\TeacherMetaRequest;
use App\Modules\Tenancy\Http\Resources\TeacherMetaResource;
use App\Modules\Tenancy\Models\TeacherMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/meta (M02) — the teacher's own key/value metadata (SEO tags, custom
 * head data, …). Tenant-scoped by the BelongsToTenant global scope, so route-
 * model binding on {meta} can only ever resolve the current tenant's rows.
 *
 * Optional `?group=` filter on index narrows to one namespace (e.g. `seo`).
 */
class TeacherMetaController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $meta = TeacherMeta::query()
            ->when(
                $request->filled('group'),
                fn ($q) => $q->where('group', $request->string('group')),
            )
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        return TeacherMetaResource::collection($meta);
    }

    public function store(TeacherMetaRequest $request): JsonResponse
    {
        $meta = TeacherMeta::create($request->validated());

        return (new TeacherMetaResource($meta))->response()->setStatusCode(201);
    }

    public function show(TeacherMeta $meta): TeacherMetaResource
    {
        return new TeacherMetaResource($meta);
    }

    public function update(TeacherMetaRequest $request, TeacherMeta $meta): TeacherMetaResource
    {
        $meta->update($request->validated());

        return new TeacherMetaResource($meta);
    }

    public function destroy(TeacherMeta $meta): Response
    {
        $meta->delete();

        return response()->noContent();
    }
}
