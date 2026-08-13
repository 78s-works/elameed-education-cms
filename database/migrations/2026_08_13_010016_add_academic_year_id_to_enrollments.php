<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Site-wide academic-year scoping, Phase 2 (activity axis). An enrollment's access
 * target is exactly one of lesson / package / exam / course. Derive the year from
 * whichever carries one (lesson, then package, then exam — runs after exams is
 * backfilled); course-only grants have no year path and fall back to the tenant's
 * first year. `package_id` stays provenance-only; the year is a separate column.
 * Cross-year "my access" reads run without a year context, so the scope no-ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE enrollments en
            JOIN lessons l ON l.id = en.lesson_id
            SET en.academic_year_id = l.academic_year_id
            WHERE en.academic_year_id IS NULL AND en.lesson_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE enrollments en
            JOIN packages p ON p.id = en.package_id
            SET en.academic_year_id = p.academic_year_id
            WHERE en.academic_year_id IS NULL AND en.package_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE enrollments en
            JOIN exams e ON e.id = en.exam_id
            SET en.academic_year_id = e.academic_year_id
            WHERE en.academic_year_id IS NULL AND en.exam_id IS NOT NULL
        SQL);

        $this->backfillTenantFirstYear('enrollments');

        Schema::table('enrollments', function (Blueprint $table): void {
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
        Schema::table('enrollments', function (Blueprint $table): void {
            // Drop the FK first: MySQL backs it with the composite index, so the
            // index cannot be dropped while the constraint still needs it (1553).
            $table->dropForeign(['academic_year_id']);
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropColumn('academic_year_id');
        });
    }
};
