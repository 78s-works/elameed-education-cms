<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Site-wide academic-year scoping, Phase 2. An exam derives its year from its
 * linked lesson (`lesson_id`); course-level / standalone exams (lesson_id NULL)
 * have no year path, so they fall back to the tenant's first academic year
 * (same precedent as the lessons/packages backfill). Exams is the anchor for
 * questions / exam_attempts / exam_time_extensions, so it migrates first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        // 1) Derive from the linked lesson where present.
        DB::statement(<<<'SQL'
            UPDATE exams e
            JOIN lessons l ON l.id = e.lesson_id
            SET e.academic_year_id = l.academic_year_id
            WHERE e.academic_year_id IS NULL AND e.lesson_id IS NOT NULL
        SQL);

        // 2) Remaining (no lesson link) → tenant's first year.
        $this->backfillTenantFirstYear('exams');

        Schema::table('exams', function (Blueprint $table): void {
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    private function backfillTenantFirstYear(string $tableName): void
    {
        foreach (DB::table($tableName)->whereNull('academic_year_id')->distinct()->pluck('tenant_id') as $tenantId) {
            $yearId = DB::table('academic_years')
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($yearId === null) {
                $yearId = DB::table('academic_years')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'name' => 'Default',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table($tableName)
                ->where('tenant_id', $tenantId)
                ->whereNull('academic_year_id')
                ->update(['academic_year_id' => $yearId]);
        }
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
