<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\Notification;

/**
 * Creates in-app notifications (and, later, fans out to SMS/WhatsApp/email via
 * queued jobs — FR-M14). Takes an explicit tenant id so it works from webhook
 * contexts. P1 wires in-app on key events (purchase); SMS templates are P1.5.
 */
class NotificationService
{
    /**
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
