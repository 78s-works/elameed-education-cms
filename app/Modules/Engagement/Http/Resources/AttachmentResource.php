<?php

namespace App\Modules\Engagement\Http\Resources;

use App\Modules\Engagement\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'url' => $this->url(),
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'duration_sec' => $this->duration_sec,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
