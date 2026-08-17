<?php

namespace App\Modules\Engagement\Http\Resources;

use App\Modules\Engagement\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'body' => $this->body,
            'status' => $this->status->value,
            'is_hidden' => (bool) $this->is_hidden,
            'author' => [
                'uuid' => $this->user?->uuid,
                'name' => $this->user?->name,
            ],
            'lesson' => $this->whenLoaded('lesson', fn () => [
                'id' => $this->lesson?->getKey(),
                'title' => $this->lesson?->title,
            ]),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
