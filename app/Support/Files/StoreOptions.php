<?php

namespace App\Support\Files;

use App\Support\Files\Enums\DocumentStatus;
use App\Support\Files\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * The optional half of a store() call. Every field has a sane default derived
 * from the purpose, so the common case is `$documents->store($file, $purpose)`
 * with no options at all.
 */
final class StoreOptions
{
    public function __construct(
        /** Overrides the purpose's configured visibility. Rarely needed. */
        public readonly ?DocumentVisibility $visibility = null,

        /** Attach to a Pattern B owner immediately instead of leaving it unattached. */
        public readonly ?Model $owner = null,

        /** Defaults to the authenticated user. */
        public readonly ?int $ownerId = null,

        /**
         * Defaults to the resolved tenant. Required on paths that run without a
         * tenant context — the signed upload receiver authenticates by signature,
         * so nothing has resolved a tenant by the time the file arrives.
         */
        public readonly ?int $tenantId = null,

        /** duration_sec, width, height, pages — whatever the caller already knows. */
        public readonly array $meta = [],

        public readonly DocumentStatus $status = DocumentStatus::Ready,

        /** Display name; defaults to the uploaded file's client name. */
        public readonly ?string $originalName = null,
    ) {}

    public function withOwner(Model $owner): self
    {
        return new self(
            visibility: $this->visibility,
            owner: $owner,
            ownerId: $this->ownerId,
            tenantId: $this->tenantId,
            meta: $this->meta,
            status: $this->status,
            originalName: $this->originalName,
        );
    }
}
