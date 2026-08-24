<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Support\RbacGuard;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects the code-owned permission catalog into the `permissions` table (M20).
 *
 * Runs on every deploy (seeder + `rbac:sync`). Idempotent by design:
 *   - new keys are inserted,
 *   - existing keys have their label/description/group/order refreshed,
 *   - keys no longer in the enum are deleted, which cascades their role links.
 *
 * The last rule is deliberate: a permission the code no longer checks must not
 * linger in the picker, where it would read as a granted capability that
 * enforces nothing.
 *
 * Permissions are GLOBAL — they carry no tenant. Only roles are per-academy.
 */
class PermissionCatalogSync
{
    /** @return array{created:int,updated:int,deleted:int} */
    public function sync(string $guard = RbacGuard::NAME): array
    {
        $catalog = PermissionEnum::catalog();
        $keys = array_column($catalog, 'key');

        $existing = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('id', 'name');

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($catalog, $guard, $existing, &$created, &$updated): void {
            foreach ($catalog as $row) {
                $attributes = [
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'group' => $row['group'],
                    'sort_order' => $row['sort_order'],
                ];

                if ($existing->has($row['key'])) {
                    Permission::query()->whereKey($existing[$row['key']])->update($attributes);
                    $updated++;

                    continue;
                }

                Permission::create($attributes + [
                    'name' => $row['key'],
                    'guard_name' => $guard,
                ]);
                $created++;
            }
        });

        $deleted = Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', $keys)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }
}
