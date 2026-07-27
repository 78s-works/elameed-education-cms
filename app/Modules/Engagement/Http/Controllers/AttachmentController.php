<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Http\Requests\StoreAttachmentRequest;
use App\Modules\Engagement\Http\Resources\AttachmentResource;
use App\Modules\Engagement\Models\Attachment;
use Illuminate\Http\JsonResponse;

/**
 * POST /attachments (M09, FR-M09-05). Stores an image/voice-note/file and returns
 * its uuid; the client then references it in a comment's `attachment_ids`. The
 * attachment is unattached until linked, and tenant_id is filled by BelongsToTenant.
 */
class AttachmentController
{
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $disk = (string) config('media.attachments_disk', 'public');

        $attachment = new Attachment([
            'kind' => $this->kindFor($file->getClientOriginalExtension()),
            'storage_key' => $file->store('attachments', $disk),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'duration_sec' => $request->integer('duration_sec') ?: null,
            'uploaded_by' => $request->user()->getKey(),
        ]);
        $attachment->save();

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    private function kindFor(string $extension): string
    {
        $extension = strtolower($extension);

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => Attachment::KIND_IMAGE,
            in_array($extension, ['mp3', 'm4a', 'ogg', 'wav', 'webm'], true) => Attachment::KIND_AUDIO,
            default => Attachment::KIND_FILE,
        };
    }
}
