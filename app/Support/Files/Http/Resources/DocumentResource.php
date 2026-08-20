<?php

namespace App\Support\Files\Http\Resources;

use App\Support\Files\DocumentLinkResolver;
use App\Support\Files\DocumentService;
use App\Support\Files\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a stored file. Replaces the bare `url` string that used to be
 * sprinkled through payloads: a private file has no stable URL any more, so the
 * client gets a short-lived signed one plus the expiry it should refresh at.
 *
 * `linked_to` is resolved only when asked for — the files tabs want it, a lesson
 * payload embedding its own PDF already knows what the file belongs to.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    private bool $withLink = false;

    /** Include what this file is attached to (one extra query per document). */
    public function withLink(bool $include = true): self
    {
        $this->withLink = $include;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $documents = app(DocumentService::class);

        $payload = [
            'uuid' => $this->uuid,
            'name' => $this->original_name,
            'kind' => $this->kind->value,
            'purpose' => $this->purpose->value,
            'mime' => $this->mime,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'is_public' => $this->isPublic(),
            'previewable' => $this->kind->isPreviewable(),
            'download_url' => $documents->url($this->resource),
            'uploaded_by' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner?->getKey(),
                'name' => $this->owner?->name,
            ]),
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        // Only a signed URL expires; a public one is stable, so saying it expires
        // would make clients refresh for nothing.
        if (! $this->isPublic()) {
            $payload['expires_at'] = now()
                ->addSeconds((int) config('documents.signed_url_ttl', 300))
                ->toIso8601String();
        }

        if ($this->withLink) {
            $payload['linked_to'] = app(DocumentLinkResolver::class)
                ->resolve($this->resource)?->toArray();
        }

        return $payload;
    }
}
