<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\ParentMagicLink;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;

/**
 * Mints and consumes guardian magic-link tokens (VD R11). All queries run through
 * the ParentMagicLink `BelongsToTenant` scope, so issue/rotate/consume are tenant-
 * isolated. Tokens are permanent + revocable (VD-D5): issuing rotates — any prior
 * active link for the parent is deactivated so only one link works at a time.
 */
class ParentMagicLinkService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Issue (rotating) a fresh magic link for a guardian and return the RAW token.
     * The raw token is shown once to the teacher; only its hash is stored.
     */
    public function issueFor(User $parent): string
    {
        $tenantId = $this->context->tenantOrFail()->getKey();

        // Rotate: any existing link for this guardian stops working.
        ParentMagicLink::query()
            ->where('parent_user_id', $parent->getKey())
            ->update(['is_active' => false]);

        $raw = Str::random(64); // cryptographically random (random_bytes under the hood)

        $link = new ParentMagicLink([
            'parent_user_id' => $parent->getKey(),
            'token_hash' => ParentMagicLink::hash($raw),
            'is_active' => true,
        ]);
        $link->tenant_id = $tenantId;
        $link->save();

        return $raw;
    }

    /** Deactivate every link for a guardian (explicit revoke). */
    public function revokeFor(User $parent): void
    {
        ParentMagicLink::query()
            ->where('parent_user_id', $parent->getKey())
            ->update(['is_active' => false]);
    }

    /**
     * Resolve an active link from a raw token within the current tenant, or null.
     * Stamps `last_used_at` on success. Permanent — no expiry check.
     */
    public function consume(string $token): ?ParentMagicLink
    {
        $link = ParentMagicLink::query()
            ->where('token_hash', ParentMagicLink::hash($token))
            ->where('is_active', true)
            ->first();

        if ($link === null) {
            return null;
        }

        $link->forceFill(['last_used_at' => now()])->save();

        return $link;
    }
}
