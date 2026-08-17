<?php

namespace App\Modules\Centers\Http\Controllers\Teacher;

use App\Modules\Catalog\Enums\AccessMode;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Centers\Http\Requests\CenterSessionRequest;
use App\Modules\Centers\Http\Resources\CenterSessionResource;
use App\Modules\Centers\Models\Center;
use App\Modules\Centers\Models\CenterSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Center sessions CRUD (year-scoped). A session bundles 0+ lessons and is the
 * unit attendance is taken against. A session that already has attendance rows
 * cannot be deleted (422).
 */
class CenterSessionController
{
    public function index(): AnonymousResourceCollection
    {
        $sessions = CenterSession::query()
            ->with(['center:id,uuid,name', 'lessons:id,title'])
            ->withCount('attendance')
            ->latest('id')
            ->paginate(50);

        return CenterSessionResource::collection($sessions);
    }

    public function store(CenterSessionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $center = Center::query()->where('uuid', $data['center_uuid'])->firstOrFail();

        $session = new CenterSession([
            'center_id' => $center->id,
            'name' => $data['name'],
            'session_at' => $data['session_at'] ?? null,
        ]);
        $session->save(); // tenant + academic_year auto-stamped by traits

        $session->lessons()->sync($this->resolveLessonIds($data['lessons'] ?? []));

        return response()->json(
            ['data' => new CenterSessionResource($session->load(['center:id,uuid,name', 'lessons:id,title']))],
            201,
        );
    }

    public function update(CenterSessionRequest $request, CenterSession $session): JsonResponse
    {
        $data = $request->validated();
        $center = Center::query()->where('uuid', $data['center_uuid'])->firstOrFail();

        $session->update([
            'center_id' => $center->id,
            'name' => $data['name'],
            'session_at' => $data['session_at'] ?? null,
        ]);
        $session->lessons()->sync($this->resolveLessonIds($data['lessons'] ?? []));

        return response()->json(
            ['data' => new CenterSessionResource($session->load(['center:id,uuid,name', 'lessons:id,title']))],
        );
    }

    public function destroy(CenterSession $session): JsonResponse
    {
        if ($session->attendance()->exists()) {
            throw new UnprocessableEntityHttpException('This session has attendance and cannot be deleted.');
        }

        $session->lessons()->detach();
        $session->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Keep only lesson ids that exist within the tenant + active year AND are
     * center-accessible (access_mode center/both) — a center session never
     * bundles online-only content.
     */
    private function resolveLessonIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Lesson::query()
            ->whereIn('id', $ids)
            ->whereIn('access_mode', [AccessMode::Center->value, AccessMode::Both->value])
            ->pluck('id')->all();
    }
}
