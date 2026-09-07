<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\Notification;

/**
 * @deprecated Superseded by the notification engine. Nothing writes through this
 * any more.
 *
 * The platform used to have two inboxes: this simple feed (`notifications`,
 * surfaced at `/me/notifications`) and the engine's (`new_notifications`, at
 * `/me/inbox`). A student had to be shown both, merged client-side, and neither
 * side could be reworded by a teacher or delivered on any channel but in-app.
 *
 * Every writer now dispatches through `NotificationEngineService` instead, so
 * the engine's inbox is THE inbox. This class and the legacy table are kept
 * read-only for the rows written before that change; delete both once those
 * rows no longer matter.
 */
class NotificationService
{
    /**
     * @deprecated Use NotificationEngineService::dispatch() with a catalog key.
     *
     * @param  array<string, mixed>  $payload
     */
    public function inApp(int $tenantId, int $userId, string $type, array $payload = []): Notification
    {
        $notification = new Notification([
            'user_id' => $userId,
            'channel' => 'in_app',
            'type' => $type,
            'payload' => $payload,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $notification->tenant_id = $tenantId;
        $notification->save();

        return $notification;
    }
}
