<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 2 (activity axis). An attempt inherits
 * its exam's year (`exam_id` is NOT NULL, runs after exams is backfilled) — fully
 * derivable, no fallback needed. Cross-year student/parent result views read this
 * without a year context, so the scope no-ops there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE exam_attempts a
            JOIN exams e ON e.id = a.exam_id
            SET a.academic_year_id = e.academic_year_id
            WHERE a.academic_year_id IS NULL
        SQL);

        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
