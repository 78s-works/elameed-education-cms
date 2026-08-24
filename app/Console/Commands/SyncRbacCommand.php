<?php

namespace App\Console\Commands;

use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Services\MembershipRoleAssigner;
use App\Modules\Identity\Services\PermissionCatalogSync;
use App\Modules\Identity\Services\RoleTemplateSync;
use App\Modules\Identity\Services\TenantRoleProvisioner;
use App\Modules\Identity\Support\RbacGuard;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Brings the whole authorization layer in step with the code (M20): catalog →
 * templates → per-tenant role copies. Safe to run on every deploy.
 *
 * What it does NOT do: overwrite roles a teacher owns. Only missing copies are
 * created, plus the owner role, which is code-derived by design.
 */
class SyncRbacCommand extends Command
{
    protected $signature = 'rbac:sync {--tenant= : Limit provisioning to one tenant id or slug}';

    protected $description = 'Sync the permission catalog, role templates and per-tenant role copies';

    public function handle(
        PermissionCatalogSync $permissions,
        RoleTemplateSync $templates,
        TenantRoleProvisioner $provisioner,
        MembershipRoleAssigner $assigner,
    ): int {
        // Heal guard drift first. Laravel's Authenticate middleware rewrites
        // `auth.defaults.guard` per request, so rows written during an API call
        // could land on `sanctum` while the catalog lives on `web` — a role on the
        // wrong guard matches no permission and no user, silently granting nothing.
        $drifted = \Illuminate\Support\Facades\DB::table('roles')
            ->where('guard_name', '!=', RbacGuard::NAME)
            ->update(['guard_name' => RbacGuard::NAME]);

        if ($drifted > 0) {
            $this->warn(sprintf('Repaired %d role(s) written under the wrong guard.', $drifted));
        }

        $result = $permissions->sync();
        $this->info(sprintf(
            'Permissions: %d created, %d updated, %d removed.',
            $result['created'],
            $result['updated'],
            $result['deleted'],
        ));

        $templates->sync();
        $this->info('Role templates synced.');

        $query = Tenant::query();

        if ($this->option('tenant') !== null) {
            $needle = (string) $this->option('tenant');
            $query->where(fn ($q) => $q->where('id', $needle)->orWhere('slug', $needle));
        }

        $created = 0;
        $synced = 0;

        foreach ($query->cursor() as $tenant) {
            $counts = $provisioner->provision($tenant);
            $created += $counts['created'];
            $synced += $counts['synced'];
        }

        $this->info(sprintf('Tenant roles: %d copies created, %d owner roles re-synced.', $created, $synced));

        // Backfill: every membership must hold the baseline role of its kind.
        // Idempotent — assignRole is a no-op when the user already holds it.
        $memberships = 0;

        TenantUser::query()
            ->when($this->option('tenant') !== null, fn ($q) => $q->whereIn('tenant_id', $query->clone()->select('id')))
            ->with('user')
            ->chunkById(500, function ($chunk) use ($assigner, &$memberships): void {
                foreach ($chunk as $membership) {
                    $assigner->syncBaseline($membership);
                    $memberships++;
                }
            });

        $this->info(sprintf('Memberships: %d baseline roles assigned.', $memberships));

        return self::SUCCESS;
    }
}
