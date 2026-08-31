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

                    // System roles carry the platform's own naming: nobody can
                    // rename them from the academy panel, so a copy stamped
                    // before the Arabic labels landed would keep showing an
                    // English name the teacher cannot fix.
                    if ($key->isSystem() && $role->name !== $template->name) {
                        $role->forceFill([
                            'name' => $template->name,
                            'description' => $template->description,
                        ])->save();
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
    /**
     * What a resync WOULD do, per academy, without doing any of it.
     *
     * The push button overwrites permission sets teachers customised, so the
     * admin has to see three things before pressing it: which academies are
     * affected (by name), what each one gains and loses, and which of them
     * customised their copy — those are the ones with something to lose.
     *
     * @return list<array{
     *   tenant_uuid:?string, tenant_name:?string, role_id:int,
     *   adds:list<string>, removes:list<string>, customised:bool
     * }>
     */
    public function previewTemplateResync(RoleTemplate $template): array
    {
        /** @var RoleTemplateKey $key */
        $key = $template->key;

        $target = $template->permissions->pluck('name')->sort()->values()->all();
        $roles = Role::query()->where('template_key', $key->value)->with('permissions')->get();
        // withTrashed: a closed academy still holds its copy of the role, and a
        // row that renders as "—" because its academy was archived is worse
        // than one that says so.
        $tenants = Tenant::withTrashed()
            ->whereIn('id', $roles->pluck('tenant_id')->filter()->unique())
            ->get(['id', 'uuid', 'name', 'deleted_at'])
            ->keyBy('id');

        return $roles->map(function (Role $role) use ($target, $tenants): array {
            $current = $role->permissions->pluck('name')->sort()->values()->all();
            $tenant = $tenants->get($role->tenant_id);

            return [
                'tenant_uuid' => $tenant?->uuid,
                'tenant_name' => $tenant?->name,
                'tenant_deleted' => $tenant?->deleted_at !== null,
                'role_id' => (int) $role->getKey(),
                'adds' => array_values(array_diff($target, $current)),
                'removes' => array_values(array_diff($current, $target)),
                // "Customised" means this copy no longer matches the template —
                // exactly the academies whose local changes a push destroys.
                'customised' => $current !== $target,
            ];
        })->values()->all();
    }

    /**
     * Overwrite academies' copies of this template with its current set.
     *
     * `$tenantUuids` scopes the push to a chosen subset; null means every
     * academy holding a copy. Returns the roles touched, keyed by tenant, so
     * the caller can write one audit entry per affected academy.
     *
     * @param  list<string>|null  $tenantUuids
     * @return list<array{tenant_id:?int, tenant_uuid:?string, tenant_name:?string, role_id:int}>
     */
    public function resyncTemplateEverywhere(RoleTemplate $template, ?array $tenantUuids = null): array
    {
        /** @var RoleTemplateKey $key */
        $key = $template->key;

        $roles = Role::query()->where('template_key', $key->value)->get();

        // withTrashed: a closed academy still holds its copy of the role, and a
        // row that renders as "—" because its academy was archived is worse
        // than one that says so.
        $tenants = Tenant::withTrashed()
            ->whereIn('id', $roles->pluck('tenant_id')->filter()->unique())
            ->get(['id', 'uuid', 'name', 'deleted_at'])
            ->keyBy('id');

        if ($tenantUuids !== null) {
            $allowed = $tenants->whereIn('uuid', $tenantUuids)->keys()->all();
            $roles = $roles->whereIn('tenant_id', $allowed);
        }

        $touched = [];

        foreach ($roles as $role) {
            $previousTeam = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($role->tenant_id);

            try {
                $role->syncPermissions($template->permissions);
            } finally {
                $this->registrar->setPermissionsTeamId($previousTeam);
            }

            $tenant = $tenants->get($role->tenant_id);
            $touched[] = [
                'tenant_id' => $role->tenant_id,
                'tenant_uuid' => $tenant?->uuid,
                'tenant_name' => $tenant?->name,
                'role_id' => (int) $role->getKey(),
            ];
        }

        $this->registrar->forgetCachedPermissions();

        return $touched;
    }
}
