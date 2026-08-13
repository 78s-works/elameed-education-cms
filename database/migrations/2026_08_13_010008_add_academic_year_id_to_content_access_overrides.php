<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (content axis). A manual access
 * override targets one of lesson_id / section_id / unit_id. Backfill the year
 * from whichever content it points at (lesson first, then section). Legacy
 * unit_id-only rows keep a NULL year (the `units` table was retired) — harmless,
 * the trait no-ops on null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_access_overrides', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE content_access_overrides o
            JOIN lessons l ON l.id = o.lesson_id
            SET o.academic_year_id = l.academic_year_id
            WHERE o.academic_year_id IS NULL AND o.lesson_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE content_access_overrides o
            JOIN lesson_sections s ON s.id = o.section_id
            SET o.academic_year_id = s.academic_year_id
            WHERE o.academic_year_id IS NULL AND o.section_id IS NOT NULL
        SQL);

        Schema::table('content_access_overrides', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('content_access_overrides', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
