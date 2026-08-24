<?php

namespace Database\Seeders;

use App\Modules\Identity\Services\RoleTemplateSync;
use Illuminate\Database\Seeder;

/**
 * Refreshes the platform role templates (M20). Runs after PermissionCatalogSeeder
 * — a template links permission rows, so the catalog must exist first.
 */
class RoleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        app(RoleTemplateSync::class)->sync();

        $this->command?->info('Role templates synced.');
    }
}
