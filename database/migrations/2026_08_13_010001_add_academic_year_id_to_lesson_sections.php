<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (content axis). A lesson part lives
 * under its parent lesson's year: backfill `academic_year_id` from
 * `lesson_sections.lesson_id -> lessons.academic_year_id`. Kept nullable (the
 * BelongsToAcademicYear trait no-ops on null / no year context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE lesson_sections s
            JOIN lessons l ON l.id = s.lesson_id
            SET s.academic_year_id = l.academic_year_id
            WHERE s.academic_year_id IS NULL
        SQL);

        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
