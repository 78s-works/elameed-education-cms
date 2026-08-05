<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\PartPassOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PartPassOverride
 */
class PartPassOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_section_id' => $this->lesson_section_id,
            // Public identifiers are uuids; expose them when the relations are loaded.
            'user_id' => $this->whenLoaded('student', fn () => $this->student?->uuid),
            'granted_by' => $this->whenLoaded('grantedBy', fn () => $this->grantedBy?->uuid),
            'granted_at' => $this->granted_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
