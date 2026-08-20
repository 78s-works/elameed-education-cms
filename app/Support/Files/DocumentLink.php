<?php

namespace App\Support\Files;

use Illuminate\Database\Eloquent\Model;

/**
 * What a document is attached to, in a form both the policy and the API can use:
 * the owning model for authorization, and a type/label pair for the files tab to
 * show ("Chapter 3 — worksheet") and for the 409 the delete endpoint returns.
 */
final class DocumentLink
{
    public function __construct(
        public readonly Model $owner,
        public readonly string $type,
        public readonly string $label,
        public readonly ?string $uuid = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'uuid' => $this->uuid,
            'label' => $this->label,
        ], static fn ($value) => $value !== null);
    }
}
