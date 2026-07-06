<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /me/notifications (M10, M14) — the current user's in-app notifications in the
 * current tenant.
 */
class NotificationController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->where('channel', 'in_app')
            ->latest('id')
            ->paginate(30);

        return NotificationResource::collection($items);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->getKey(), 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['data' => ['read' => true]]);
    }
}
