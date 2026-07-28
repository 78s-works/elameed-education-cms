<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\NotificationTemplateTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationTemplateTranslation
 */
class NotificationTranslationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'language' => $this->language,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
