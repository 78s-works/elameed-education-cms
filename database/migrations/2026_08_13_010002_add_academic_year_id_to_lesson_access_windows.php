<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (content axis). A lesson access
 * window inherits the year of its lesson: backfill from
 * `lesson_access_windows.lesson_id -> lessons.academic_year_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_access_windows', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE lesson_access_windows w
            JOIN lessons l ON l.id = w.lesson_id
            SET w.academic_year_id = l.academic_year_id
            WHERE w.academic_year_id IS NULL
        SQL);

        Schema::table('lesson_access_windows', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_access_windows', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
