<?php

namespace Database\Seeders;

use App\Modules\Identity\Services\PermissionCatalogSync;
use Illuminate\Database\Seeder;

/**
 * Mirrors the code-owned permission catalog into the database (M20). Safe to
 * re-run on every deploy — see PermissionCatalogSync for the merge rules.
 */
class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(PermissionCatalogSync::class)->sync();

        $this->command?->info(sprintf(
            'Permissions synced: %d created, %d updated, %d removed.',
            $result['created'],
            $result['updated'],
            $result['deleted'],
        ));
    }
}
