<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationType
 */
class NotificationTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'module' => $this->module->value,
            'severity' => $this->severity->value,
            'is_system' => (bool) $this->is_system,
            'status' => $this->status->value,
            'templates' => NotificationTemplateResource::collection($this->whenLoaded('templates')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
