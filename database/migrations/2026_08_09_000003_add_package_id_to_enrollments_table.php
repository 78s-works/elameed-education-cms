<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `package_id` to enrollments (B15 / VD LP-D2). A package purchase fans out
 * into per-lesson enrollments (one row per descendant lesson); each such row
 * records the package it came from here, so provenance survives the fan-out.
 *
 * Access itself is still granted per-lesson (`lesson_id`) — `package_id` is only
 * provenance, never an access key. It is nulled (not cascaded) when the package
 * is deleted: the student keeps the lessons they paid for. Replaces the retired
 * `bundle_id` provenance column for the new recursive-package curriculum
 * (`bundle_id` stays dormant — Bundle retired, VD §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('package_id')->nullable()->after('lesson_id')->constrained('packages')->nullOnDelete();
            $table->index(['tenant_id', 'user_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropForeign(['package_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'package_id']);
            $table->dropColumn('package_id');
        });
    }
};
