<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (activity axis). An extension request
 * hangs off an access window, which hangs off a lesson: backfill two hops,
 * `lesson_extension_requests.access_window_id -> lesson_access_windows.lesson_id
 * -> lessons.academic_year_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_extension_requests', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE lesson_extension_requests r
            JOIN lesson_access_windows w ON w.id = r.access_window_id
            JOIN lessons l ON l.id = w.lesson_id
            SET r.academic_year_id = l.academic_year_id
            WHERE r.academic_year_id IS NULL
        SQL);

        Schema::table('lesson_extension_requests', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_extension_requests', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
