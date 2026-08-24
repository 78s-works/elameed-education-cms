<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties Spatie's team key to the tenants table (M20).
 *
 * The package ships `tenant_id` as a bare indexed column, which leaves roles and
 * assignments behind when an academy is deleted — orphans that a later tenant
 * could inherit if ids are ever reused. Postgres RLS is not available on our
 * MySQL deployment, so the database's only structural guarantee here is this
 * constraint; without it the isolation story rests entirely on application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->whereNotIn('tenant_id', DB::table('tenants')->select('id'))->delete();
        DB::table('model_has_roles')->whereNotIn('tenant_id', DB::table('tenants')->select('id'))->delete();
        DB::table('model_has_permissions')->whereNotIn('tenant_id', DB::table('tenants')->select('id'))->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('model_has_permissions', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('model_has_roles', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        Schema::table('roles', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
    }
};
