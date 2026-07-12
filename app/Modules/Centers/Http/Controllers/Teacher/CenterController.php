<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Centers\Http\Requests\CenterRequest;
use App\Modules\Centers\Http\Resources\CenterResource;
use App\Modules\Centers\Models\Center;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/centers (M12) — the teacher's physical branches. Tenant-scoped.
 */
class CenterController
{
    public function index(): AnonymousResourceCollection
    {
        return CenterResource::collection(Center::query()->latest('id')->get());
    }

    public function store(CenterRequest $request): JsonResponse
    {
        $center = Center::create($request->validated());

        return (new CenterResource($center))->response()->setStatusCode(201);
    }

    public function update(CenterRequest $request, Center $center): CenterResource
    {
        $center->update($request->validated());

        return new CenterResource($center);
    }

    public function destroy(Center $center): Response
    {
        $center->delete();

        return response()->noContent();
    }
}
