<?php

namespace App\Modules\Engagement\Http\Resources;

use App\Modules\Engagement\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'subject' => $this->subject,
            'body' => $this->body,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'replies' => TicketReplyResource::collection($this->whenLoaded('replies')),
            'replies_count' => $this->whenCounted('replies'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
