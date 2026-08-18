<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Enums\ContentAccessTarget;
use App\Modules\Catalog\Models\ContentAccessOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentAccessOverride
 */
class ContentAccessOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$targetType, $targetId] = $this->target();

        return [
            'id' => $this->id,
            'student_uuid' => $this->whenLoaded('user', fn () => $this->user?->uuid),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'note' => $this->note,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'active' => $this->revoked_at === null,
        ];
    }

    /** @return array{0: ?string, 1: ?int} target_type + target_id from the set column. */
    private function target(): array
    {
        if ($this->lesson_id !== null) {
            return [ContentAccessTarget::Lesson->value, (int) $this->lesson_id];
        }
        if ($this->section_id !== null) {
            return [ContentAccessTarget::Section->value, (int) $this->section_id];
        }

        return [null, null];
    }
}
