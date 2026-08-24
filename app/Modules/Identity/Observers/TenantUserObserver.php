<?php

namespace App\Modules\Identity\Observers;

use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\MembershipRoleAssigner;

/**
 * Keeps every membership's baseline role in step with its kind (M20).
 *
 * Central on purpose: memberships are created from five different places
 * (self-registration, teacher-created students, assistants, parent links, the
 * platform-admin tenant form). Patching each one would leave the invariant
 * "no user without a role" to be re-remembered every time a sixth appears.
 *
 * Only the BASELINE role is managed here. Extra roles a teacher grants an
 * assistant are untouched, except when the membership is deleted — then all of
 * that user's roles in that academy go with it, because the person is no longer
 * a member of it at all.
 */
class TenantUserObserver
{
    public function __construct(private readonly MembershipRoleAssigner $assigner) {}

    public function created(TenantUser $membership): void
    {
        $this->assigner->syncBaseline($membership);
    }

    /** A membership whose kind changed swaps one baseline role for the other. */
    public function updated(TenantUser $membership): void
    {
        if ($membership->wasChanged('role')) {
            $this->assigner->syncBaseline($membership, $membership->getOriginal('role'));
        }
    }

    public function deleted(TenantUser $membership): void
    {
        $this->assigner->revokeAll($membership);
    }
}
