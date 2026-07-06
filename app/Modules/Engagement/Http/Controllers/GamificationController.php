<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Models\User;
use App\Modules\Engagement\Models\PointsEntry;
use App\Modules\Engagement\Models\StudentBadge;
use App\Modules\Engagement\Services\PointsService;
use App\Modules\Tenancy\Models\TeacherProfile;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-facing gamification (M19): points, badges, leaderboard. The teacher
 * can hide the leaderboard (teacher_profiles.hide_ranking).
 */
class GamificationController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PointsService $points,
    ) {}

    public function points(Request $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();
        $userId = $request->user()->getKey();

        $recent = PointsEntry::query()->where('user_id', $userId)->latest('id')->limit(20)->get()
            ->map(fn (PointsEntry $e) => [
                'points' => $e->points, 'reason' => $e->reason, 'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [
            'total' => $this->points->total($tenantId, $userId),
            'recent' => $recent,
        ]]);
    }

    public function badges(Request $request): JsonResponse
    {
        $earned = StudentBadge::query()
            ->where('user_id', $request->user()->getKey())
            ->with('badge:id,name,description,icon')
            ->get()
            ->map(fn (StudentBadge $sb) => [
                'name' => $sb->badge?->name,
                'description' => $sb->badge?->description,
                'icon' => $sb->badge?->icon,
                'awarded_at' => $sb->awarded_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $earned]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        $profile = TeacherProfile::query()->first();
        if ($profile !== null && $profile->hide_ranking) {
            return response()->json(['data' => ['hidden' => true, 'entries' => []]]);
        }

        $board = $this->points->leaderboard($tenantId, 20);
        $names = User::whereIn('id', array_column($board, 'user_id'))->pluck('name', 'id');

        $entries = [];
        foreach ($board as $i => $row) {
            $entries[] = [
                'rank' => $i + 1,
                'name' => $names[$row['user_id']] ?? null,
                'points' => $row['points'],
            ];
        }

        return response()->json(['data' => ['hidden' => false, 'entries' => $entries]]);
    }
}
