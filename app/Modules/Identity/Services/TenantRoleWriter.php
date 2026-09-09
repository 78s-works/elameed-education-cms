<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Support\RbacGuard;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creating, editing and deleting an academy's own roles (M20).
 *
 * The system-role lock lives HERE, not only in the controller, so it holds for
 * every caller — a future console command or import cannot bypass it by not
 * going through HTTP. The controller refuses early for a clean error; this is
 * the backstop that makes the rule true.
 */
class TenantRoleWriter
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param  list<string>  $permissionKeys */
    public function create(int $tenantId, string $name, ?string $description, array $permissionKeys): Role
    {
        $this->assertNameFree($tenantId, $name);

        return DB::transaction(function () use ($tenantId, $name, $description, $permissionKeys): Role {
            $role = Role::create([
                'uuid' => (string) Str::uuid7(),
                'name' => $name,
                'guard_name' => RbacGuard::NAME,
                'description' => $description,
                'is_system' => false,
                'template_key' => null,
                'tenant_id' => $tenantId,
            ]);

            $this->applyPermissions($role, $permissionKeys);

            return $role;
        });
    }

    /** @param  list<string>|null  $permissionKeys */
    public function update(Role $role, ?string $name, ?string $description, ?array $permissionKeys): Role
    {
        $this->assertEditable($role);

        if ($name !== null && $name !== $role->name) {
            $this->assertNameFree((int) $role->tenant_id, $name, $role->getKey());
            $role->name = $name;
        }

        if ($description !== null) {
            $role->description = $description;
        }

        $role->save();

        if ($permissionKeys !== null) {
            $this->applyPermissions($role, $permissionKeys);
        }

        return $role->fresh(['permissions']);
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        // Assignments go with it (FK cascade), so members lose exactly this role
        // and keep the rest — no one is left with a dangling grant.
        $role->delete();
        $this->registrar->forgetCachedPermissions();
    }

    private function assertEditable(Role $role): void
    {
        if ((bool) $role->is_system) {
            throw new DomainException(
                'system_role_is_locked',
                __('System roles cannot be renamed, re-scoped or deleted.'),
                422,
            );
        }
    }

    private function assertNameFree(int $tenantId, string $name, ?int $exceptId = null): void
    {
        $exists = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw new DomainException(
                'role_name_taken',
                __('A role with this name already exists in your academy.'),
                422,
            );
        }
    }

    /** @param  list<string>  $permissionKeys */
    private function applyPermissions(Role $role, array $permissionKeys): void
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($role->tenant_id);

        try {
            $ids = Permission::query()
                ->whereIn('name', PermissionEnum::sanitize($permissionKeys))
                ->pluck('id')
                ->all();

            $role->permissions()->sync($ids);
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
            $this->registrar->forgetCachedPermissions();
        }
    }
}
