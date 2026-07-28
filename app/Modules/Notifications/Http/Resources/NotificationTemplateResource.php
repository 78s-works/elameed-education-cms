<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationTemplate
 */
class NotificationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'channel' => $this->channel->value,
            'scope' => $this->scope->value,
            'tenant_id' => $this->tenant_id,
            'is_active' => (bool) $this->is_active,
            'translations' => NotificationTranslationResource::collection($this->whenLoaded('translations')),
        ];
    }
}
