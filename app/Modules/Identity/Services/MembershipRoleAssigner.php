<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives every membership the baseline role of its kind, inside its academy (M20).
 *
 * This is what makes the system role-based rather than role-flavoured: a student
 * holds the `student` role — empty today — instead of being a user the code
 * happens to check by column. Granting that role a permission tomorrow opens the
 * matching route with no code change, and nothing in the codebase has to learn
 * about students to make that work.
 *
 * Every write pins Spatie's team id to the membership's tenant explicitly. The
 * request-scoped resolver is right for requests, but memberships are also
 * created by seeders, imports and queue jobs where no tenant is resolved — and a
 * role assignment landing on the wrong academy is a silent privilege leak.
 */
class MembershipRoleAssigner
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Assign the baseline role for this membership's kind, dropping the previous
     * baseline when the kind changed.
     *
     * @param  TenantUserRole|string|null  $previousKind  the kind before the change
     */
    public function syncBaseline(TenantUser $membership, TenantUserRole|string|null $previousKind = null): void
    {
        $user = $membership->user;

        if ($user === null) {
            return;
        }

        $this->withTeam($membership->tenant_id, function () use ($membership, $user, $previousKind): void {
            if ($previousKind !== null) {
                $previous = $this->baselineRole($membership->tenant_id, $this->kindOf($previousKind));

                if ($previous !== null) {
                    $user->removeRole($previous);
                }
            }

            $role = $this->baselineRole($membership->tenant_id, $membership->role);

            // No role row means the tenant was never provisioned (an academy
            // created before M20, or a failed provision). Say so loudly rather
            // than leaving a member silently role-less.
            if ($role === null) {
                throw new \RuntimeException(sprintf(
                    'Tenant %d has no `%s` role. Run `php artisan rbac:sync --tenant=%d`.',
                    $membership->tenant_id,
                    $membership->role->value,
                    $membership->tenant_id,
                ));
            }

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        });
    }

    /** Drop every role this user holds in this academy. */
    public function revokeAll(TenantUser $membership): void
    {
        DB::table('model_has_roles')
            ->where('tenant_id', $membership->tenant_id)
            ->where('model_type', $membership->user()->getRelated()->getMorphClass())
            ->where('model_id', $membership->user_id)
            ->delete();

        $this->registrar->forgetCachedPermissions();
    }

    private function baselineRole(int $tenantId, TenantUserRole $kind): ?Role
    {
        $templateKey = match ($kind) {
            TenantUserRole::Teacher => RoleTemplateKey::Teacher,
            TenantUserRole::Assistant => RoleTemplateKey::Assistant,
            TenantUserRole::Student => RoleTemplateKey::Student,
            TenantUserRole::Parent => RoleTemplateKey::ParentGuardian,
        };

        return Role::query()
            ->where('tenant_id', $tenantId)
            ->where('template_key', $templateKey->value)
            ->first();
    }

    private function kindOf(TenantUserRole|string $kind): TenantUserRole
    {
        return $kind instanceof TenantUserRole ? $kind : TenantUserRole::from($kind);
    }

    private function withTeam(int $tenantId, callable $callback): void
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenantId);

        try {
            $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }
}
