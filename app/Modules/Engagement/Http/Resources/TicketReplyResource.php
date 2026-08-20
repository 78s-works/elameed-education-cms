<?php

namespace App\Modules\Engagement\Http\Resources;

use App\Modules\Engagement\Models\TicketReply;
use App\Support\Files\Http\Resources\DocumentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketReply
 */
class TicketReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'body' => $this->body,
            'author' => [
                'uuid' => $this->user?->uuid,
                'name' => $this->user?->name,
            ],
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
