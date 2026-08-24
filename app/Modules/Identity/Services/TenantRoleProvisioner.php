<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Models\RoleTemplate;
use App\Modules\Identity\Support\RbacGuard;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stamps a tenant's roles from the platform templates (M20).
 *
 * Every tenant gets its OWN copy of every template, so the teacher edits rows
 * that belong to their academy and nobody else's. A copy keeps `template_key`
 * for traceability only — it is an ordinary row from that point on.
 *
 * Idempotent: re-running adds only what is missing, so a tenant created before a
 * new template existed picks it up when the platform admin runs the resync.
 *
 * The one exception is the OWNER role: its permission set is re-derived from the
 * template on every run. Nobody may edit it (not the teacher, not the platform
 * admin), so keeping it in sync is the only way a newly added permission ever
 * reaches an existing academy's owner.
 */
class TenantRoleProvisioner
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @return array{created:int,synced:int} */
    public function provision(Tenant $tenant): array
    {
        $templates = RoleTemplate::with('permissions')->orderBy('sort_order')->get();

        if ($templates->isEmpty()) {
            return ['created' => 0, 'synced' => 0];
        }

        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        $created = 0;
        $synced = 0;

        try {
            DB::transaction(function () use ($templates, $tenant, &$created, &$synced): void {
                $existing = Role::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->whereNotNull('template_key')
                    ->get()
                    ->keyBy('template_key');

                foreach ($templates as $template) {
                    /** @var RoleTemplateKey $key */
                    $key = $template->key;
                    $role = $existing->get($key->value);

                    if ($role === null) {
                        $role = Role::create([
                            'uuid' => (string) Str::uuid7(),
                            'name' => $template->name,
                            'guard_name' => RbacGuard::NAME,
                            'description' => $template->description,
                            'is_system' => $template->is_system,
                            'template_key' => $key->value,
                            'tenant_id' => $tenant->getKey(),
                        ]);

                        $role->syncPermissions($template->permissions);
                        $created++;

                        continue;
                    }

                    // Existing copies are the teacher's to keep — except the owner
                    // role, which is code-derived and re-synced (see the class docs).
                    if ($key === RoleTemplateKey::Teacher) {
                        $role->syncPermissions($template->permissions);
                        $synced++;
                    }
                }
            });
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $this->registrar->forgetCachedPermissions();
        }

        return ['created' => $created, 'synced' => $synced];
    }

    /**
     * Push a template's CURRENT permission set onto every tenant's copy of it.
     * Deliberately explicit — the platform admin runs this knowing it overwrites
     * whatever each teacher had configured on that role.
     */
    public function resyncTemplateEverywhere(RoleTemplate $template): int
    {
        /** @var RoleTemplateKey $key */
        $key = $template->key;

        $roles = Role::query()->where('template_key', $key->value)->get();

        foreach ($roles as $role) {
            $previousTeam = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($role->tenant_id);

            try {
                $role->syncPermissions($template->permissions);
            } finally {
                $this->registrar->setPermissionsTeamId($previousTeam);
            }
        }

        $this->registrar->forgetCachedPermissions();

        return $roles->count();
    }
}
