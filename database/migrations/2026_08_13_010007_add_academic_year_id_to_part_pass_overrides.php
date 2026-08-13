<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (content axis). A pass-override is
 * granted on one part, so it inherits that part's year: backfill from
 * `part_pass_overrides.lesson_section_id -> lesson_sections.academic_year_id`
 * (runs after lesson_sections is backfilled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_pass_overrides', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE part_pass_overrides o
            JOIN lesson_sections s ON s.id = o.lesson_section_id
            SET o.academic_year_id = s.academic_year_id
            WHERE o.academic_year_id IS NULL
        SQL);

        Schema::table('part_pass_overrides', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('part_pass_overrides', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
