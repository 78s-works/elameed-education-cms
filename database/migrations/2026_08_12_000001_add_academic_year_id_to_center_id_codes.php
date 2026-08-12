<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Year-scope Center ID-codes (bug fix): browsing an academic year in the teacher
 * panel was listing codes of every grade because `center_id_codes` had no
 * `academic_year_id` and its route sat outside the `academic-year` middleware.
 * This mirrors the working pattern on `lessons` / `center_exam_grades`
 * (BelongsToAcademicYear + X-Academic-Year), so the year selector filters codes.
 *
 * `grade` (1|2|3) stays: it drives the code's encoded prefix, the per-center
 * sequence counter, and the register-time binding (B21). `academic_year_id` is
 * purely the new scope dimension — academic_years carries no grade, so the two
 * never conflict.
 *
 *   1. add `academic_year_id` nullable (FK academic_years, cascade on delete);
 *   2. backfill every code to its tenant's first year (best-effort — no
 *      grade->year map exists in data; legacy rows collapse to that year);
 *   3. set it NOT NULL;
 *   4. index (tenant_id, academic_year_id) for the scoped list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('center_id_codes', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('center_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        // Backfill: point each tenant's codes at that tenant's first academic year
        // (create a Default if the tenant somehow has none). No grade->year mapping
        // exists, so pre-existing codes of every grade land in the one year.
        foreach (DB::table('center_id_codes')->distinct()->pluck('tenant_id') as $tenantId) {
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

            DB::table('center_id_codes')
                ->where('tenant_id', $tenantId)
                ->whereNull('academic_year_id')
                ->update(['academic_year_id' => $yearId]);
        }

        Schema::table('center_id_codes', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_year_id')->nullable(false)->change();
            $table->index(['tenant_id', 'academic_year_id'], 'center_id_codes_tenant_year_index');
        });
    }

    public function down(): void
    {
        Schema::table('center_id_codes', function (Blueprint $table): void {
            $table->dropIndex('center_id_codes_tenant_year_index');
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
