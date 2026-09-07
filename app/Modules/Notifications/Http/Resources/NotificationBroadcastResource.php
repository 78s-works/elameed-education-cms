<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationBroadcast
 *
 * A custom notification as its sender sees it: the copy, the audience it was
 * aimed at, the estimate confirmed at send time, and — once delivered — what
 * the engine actually managed to send.
 */
class NotificationBroadcastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'audience_type' => $this->audience_type->value,
            'audience_ids' => $this->audience_ids,
            'channels' => $this->channels,
            'title_ar' => $this->title_ar,
            'body_ar' => $this->body_ar,
            'title_en' => $this->title_en,
            'body_en' => $this->body_en,
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'estimate' => [
                'recipients' => (int) $this->recipient_count,
                'sms_recipients' => (int) $this->sms_recipient_count,
                'sms_segments' => (int) $this->sms_segments,
                'sms_cost_minor' => (int) $this->sms_cost_minor,
                'currency' => $this->currency,
            ],
            'stats' => $this->stats,
            'failure_reason' => $this->failure_reason,
            'author' => $this->whenLoaded('author', fn () => [
                'name' => $this->author?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
