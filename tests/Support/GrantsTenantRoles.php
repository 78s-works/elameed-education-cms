<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Identity\Support\RbacGuard;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Test helper: give a member a set of permissions the only way the system allows
 * it — through a role in their academy (M20).
 *
 * There is deliberately no shortcut that writes permissions straight onto a
 * membership. If a test could grant authority without a role, the test would be
 * proving something the application cannot do.
 */
trait GrantsTenantRoles
{
    /**
     * Wrap the given keys in an ad-hoc role and assign it. Returns the role.
     *
     * @param  list<string>  $permissionKeys
     */
    protected function grantPermissions(User $user, Tenant $tenant, array $permissionKeys, ?string $roleName = null): Role
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $role = Role::create([
                'uuid' => (string) Str::uuid7(),
                'name' => $roleName ?? 'Test role '.Str::random(6),
                'guard_name' => RbacGuard::NAME,
                'is_system' => false,
                'tenant_id' => $tenant->getKey(),
            ]);

            $role->permissions()->sync(
                Permission::query()->whereIn('name', $permissionKeys)->pluck('id')->all()
            );

            $user->assignRole($role);

            return $role;
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Remove a role, with the team pinned.
     *
     * Spatie detaches using the CURRENT team id, so calling removeRole() with no
     * team resolved silently removes nothing — the assignment row carries a real
     * tenant id and the delete matches none. Every production path pins the team;
     * tests must too, or they assert on a revocation that never happened.
     */
    protected function revokeRole(User $user, Tenant $tenant, Role $role): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $user->removeRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }

    /** The academy's copy of a template role, by template key. */
    protected function tenantRole(Tenant $tenant, string $templateKey): Role
    {
        return Role::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('template_key', $templateKey)
            ->firstOrFail();
    }
}
