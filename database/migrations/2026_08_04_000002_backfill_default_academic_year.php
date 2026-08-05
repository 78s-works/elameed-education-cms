<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed one "Default" academic year per existing tenant so pre-existing content
 * has a container to migrate under in later phases. Raw inserts (not the model)
 * so the BelongsToTenant creating-hook / global scope don't interfere while no
 * tenant is resolved during migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $hasYear = DB::table('academic_years')->where('tenant_id', $tenantId)->exists();

            if ($hasYear) {
                continue;
            }

            DB::table('academic_years')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'name' => 'Default',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('academic_years')->where('name', 'Default')->where('sort_order', 0)->delete();
    }
};
