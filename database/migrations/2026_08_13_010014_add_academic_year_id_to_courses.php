<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Site-wide academic-year scoping, Phase 2. Courses are a content root with no
 * parent that carries a year, so every course is backfilled to its tenant's
 * first academic year (same precedent as the lessons/packages backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        $this->backfillTenantFirstYear('courses');

        Schema::table('courses', function (Blueprint $table): void {
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
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
