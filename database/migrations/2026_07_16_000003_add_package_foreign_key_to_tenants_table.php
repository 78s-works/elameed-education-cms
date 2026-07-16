<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Now that `subscription_packages` exists, wire the FK that was deferred on the
 * original tenants migration: tenants.package_id → subscription_packages.id.
 * nullOnDelete so hard-deleting a plan detaches tenants rather than blocking
 * (retiring a plan is a soft delete and leaves the row intact). See the tenants
 * create migration and 03_Data_Model.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreign('package_id')
                ->references('id')->on('subscription_packages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
        });
    }
};
