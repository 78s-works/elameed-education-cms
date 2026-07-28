<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\LessonExtensionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LessonExtensionRequest
 */
class LessonExtensionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'access_window_id' => $this->access_window_id,
            'user_id' => $this->user_id,
            'lesson_id' => $this->whenLoaded('accessWindow', fn () => $this->accessWindow?->lesson_id),
            'status' => $this->status?->value,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
