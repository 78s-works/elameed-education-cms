<?php

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Models\Badge;
use App\Modules\Engagement\Models\PointsEntry;
use App\Modules\Engagement\Models\StudentBadge;
use Illuminate\Database\QueryException;

/**
 * Awards and tallies gamification points (FR-M19). Score is derived from the
 * append-only points_entries; awards tied to an event are idempotent so a lesson
 * or exam only ever scores once. Crossing a badge threshold auto-awards it.
 */
class PointsService
{
    public function award(int $tenantId, int $userId, int $points, string $reason, ?string $refType = null, ?int $refId = null): void
    {
        $key = $refId !== null ? "{$reason}:{$refType}:{$refId}:{$userId}" : null;

        if ($key !== null && PointsEntry::withoutGlobalScopes()->where('idempotency_key', $key)->exists()) {
            return; // already awarded for this event
        }

        try {
            $entry = new PointsEntry([
                'user_id' => $userId, 'points' => $points, 'reason' => $reason,
                'ref_type' => $refType, 'ref_id' => $refId, 'idempotency_key' => $key,
            ]);
            $entry->tenant_id = $tenantId;
            $entry->created_at = now();
            $entry->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return; // concurrent duplicate — idempotent
            }
            throw $e;
        }

        $this->awardThresholdBadges($tenantId, $userId);
    }

    public function total(int $tenantId, int $userId): int
    {
        return (int) PointsEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->sum('points');
    }

    /** @return array<int, array{user_id: int, points: int}> */
    public function leaderboard(int $tenantId, int $limit = 20): array
    {
        return PointsEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->selectRaw('user_id, SUM(points) as points')
            ->groupBy('user_id')
            ->orderByDesc('points')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'points' => (int) $r->points])
            ->all();
    }

    private function awardThresholdBadges(int $tenantId, int $userId): void
    {
        $total = $this->total($tenantId, $userId);

        $earned = StudentBadge::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->pluck('badge_id');

        Badge::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('points_threshold')
            ->where('points_threshold', '<=', $total)
            ->whereNotIn('id', $earned)
            ->get()
            ->each(function (Badge $badge) use ($tenantId, $userId): void {
                $sb = new StudentBadge(['user_id' => $userId, 'badge_id' => $badge->id, 'awarded_at' => now()]);
                $sb->tenant_id = $tenantId;
                $sb->save();
            });
    }
}
