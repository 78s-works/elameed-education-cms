<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (activity axis). A forum comment
 * hangs off a lesson, so it inherits that lesson's year: backfill from
 * `comments.lesson_id -> lessons.academic_year_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE comments c
            JOIN lessons l ON l.id = c.lesson_id
            SET c.academic_year_id = l.academic_year_id
            WHERE c.academic_year_id IS NULL
        SQL);

        Schema::table('comments', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
