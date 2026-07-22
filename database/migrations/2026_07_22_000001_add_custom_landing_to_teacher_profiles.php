<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom-landing switch (FR-M02): a teacher can opt out of the CMS-built landing
 * sections and have the SPA render its own bundled `custom/<tenant-slug>/` page
 * instead. Defaults OFF — every academy keeps the dynamic LANDING_CONTRACT_V2
 * sections until the teacher explicitly turns this on. The custom page itself
 * lives in the frontend; the backend only stores the flag and exposes it (with
 * the tenant slug) on GET /tenant/context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->boolean('custom_landing_enabled')->default(false)->after('registration_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('custom_landing_enabled');
        });
    }
};
