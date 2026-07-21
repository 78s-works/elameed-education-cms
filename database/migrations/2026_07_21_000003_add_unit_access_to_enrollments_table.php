<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `enrollments` (the single source of truth for access) so a row can grant
 * a UNIT as well as a whole course — needed for packages that bundle individual
 * units/chapters. Exactly one of course_id / unit_id is set per row. `bundle_id`
 * (already present as a plain column since P1) now gets a real FK to `bundles`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('course_id')
                ->constrained('units')->cascadeOnDelete();
            // bundle_id existed as an unindexed placeholder column; give it a FK now
            // that the bundles table exists. nullOnDelete keeps access alive if a
            // retired bundle is ever hard-deleted.
            $table->foreign('bundle_id')->references('id')->on('bundles')->nullOnDelete();

            $table->index(['tenant_id', 'user_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['bundle_id']);
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
