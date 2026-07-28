<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationMessage
 *
 * Student inbox row (doc 10 §7 database channel). The engine's delivered
 * message, read via /me/inbox.
 */
class NotificationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'type_key' => $this->whenLoaded('event', fn () => $this->event->type?->key),
            'channel' => $this->channel->value,
            'title' => $this->title,
            'body' => $this->body,
            'is_read' => (bool) $this->is_read,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
