<?php

namespace App\Modules\Engagement\Http\Controllers\Teacher;

use App\Modules\Engagement\Models\Badge;
use App\Modules\Tenancy\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Teacher-defined badges + the hide-ranking toggle (M19).
 */
class BadgeController
{
    public function index(): JsonResponse
    {
        $badges = Badge::query()->orderBy('points_threshold')->get()
            ->map(fn (Badge $b) => [
                'id' => $b->id, 'name' => $b->name, 'description' => $b->description,
                'icon' => $b->icon, 'points_threshold' => $b->points_threshold,
            ]);

        return response()->json(['data' => $badges]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:255'],
            'points_threshold' => ['nullable', 'integer', 'min:1'],
        ]);

        $badge = Badge::create($data);

        return response()->json(['data' => ['id' => $badge->id, 'name' => $badge->name]], 201);
    }

    public function destroy(Badge $badge): Response
    {
        $badge->delete();

        return response()->noContent();
    }

    public function settings(): JsonResponse
    {
        $profile = TeacherProfile::query()->firstOrNew([]);

        return response()->json(['data' => ['hide_ranking' => (bool) $profile->hide_ranking]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['hide_ranking' => ['required', 'boolean']]);

        $profile = TeacherProfile::query()->firstOrNew([]);
        $profile->fill(['hide_ranking' => $data['hide_ranking']])->save();

        return response()->json(['data' => ['hide_ranking' => (bool) $profile->hide_ranking]]);
    }
}
