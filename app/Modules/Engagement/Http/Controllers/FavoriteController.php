<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Models\Favorite;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Student content favorites (M20). A favorite targets EITHER a standalone lesson
 * OR a recursive package (`target_type`/`target_id`, VD §7 — `courses` retired).
 * Tenant-scoped to the current academy.
 */
class FavoriteController
{
    public function index(Request $request): JsonResponse
    {
        $items = Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('id')
            ->get()
            ->map(function (Favorite $f): array {
                $target = $f->target();

                return [
                    'target_type' => $f->target_type,
                    'target_id' => $f->target_id,
                    // Lesson uses `title`, package uses `name`.
                    'title' => $target?->title ?? $target?->name,
                ];
            });

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->tenantId();

        $data = $request->validate([
            'target_type' => ['required', Rule::in(Favorite::targetTypes())],
            'target_id' => [
                'required', 'integer',
                Rule::exists(
                    $request->input('target_type') === Favorite::TARGET_PACKAGE ? 'packages' : 'lessons',
                    'id',
                )->where('tenant_id', $tenantId),
            ],
        ]);

        Favorite::query()->firstOrCreate([
            'user_id' => $request->user()->getKey(),
            'target_type' => $data['target_type'],
            'target_id' => (int) $data['target_id'],
        ]);

        return response()->json(['data' => ['favorited' => true]], 201);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->forTarget($type, $id)
            ->delete();

        return response()->json(['data' => ['favorited' => false]]);
    }
}
