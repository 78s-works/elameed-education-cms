<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Http\Resources\NotificationMessageResource;
use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /me/inbox (M10) — the current user's in-app inbox for engine-delivered
 * `database` notifications (the `new_notifications` table). Distinct from the
 * legacy /me/notifications (old simple `notifications` table), which is left
 * untouched. Tenant-scoped by BelongsToTenant; further filtered to the caller.
 */
class InboxController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = NotificationMessage::query()
            ->where('user_id', $request->user()->getKey())
            ->where('channel', NotificationChannel::Database->value)
            ->with('event.type')
            ->latest('id')
            ->paginate(30);

        return NotificationMessageResource::collection($items);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = NotificationMessage::query()
            ->where('user_id', $request->user()->getKey())
            ->where('channel', NotificationChannel::Database->value)
            ->where('is_read', false)
            ->count();

        return response()->json(['data' => ['unread' => $count]]);
    }

    public function read(Request $request, NotificationMessage $message): JsonResponse
    {
        abort_unless($message->user_id === $request->user()->getKey(), 404);

        if (! $message->is_read) {
            $message->update(['is_read' => true]);
        }

        return response()->json(['data' => ['read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = NotificationMessage::query()
            ->where('user_id', $request->user()->getKey())
            ->where('channel', NotificationChannel::Database->value)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['data' => ['marked_read' => $updated]]);
    }
}
