<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide academic-year scoping, Phase 1 (content axis). A package item lives
 * under its parent package's year: backfill from
 * `package_items.package_id -> packages.academic_year_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_items', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE package_items i
            JOIN packages p ON p.id = i.package_id
            SET i.academic_year_id = p.academic_year_id
            WHERE i.academic_year_id IS NULL
        SQL);

        Schema::table('package_items', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('package_items', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
