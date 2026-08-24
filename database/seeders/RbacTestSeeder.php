<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The minimum authorization data every test needs (M20): the permission catalog
 * and the role templates a new tenant is stamped from.
 *
 * Tests create tenants constantly; without templates a tenant would come up with
 * no roles, and creating any member would fail. Kept separate from DatabaseSeeder
 * so the suite pays for the catalog only, not the demo academies.
 */
class RbacTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionCatalogSeeder::class,
            RoleTemplateSeeder::class,
        ]);
    }
}
