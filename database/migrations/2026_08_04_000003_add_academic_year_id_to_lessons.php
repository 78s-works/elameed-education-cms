<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scope lessons to an academic year (VD change set §7 LP-10) and make them
 * standalone (LP-3): a lesson no longer needs a Unit/Course parent.
 *
 *   1. add `academic_year_id` nullable (FK academic_years, cascade on delete);
 *   2. backfill every lesson to its tenant's Default year;
 *   3. set it NOT NULL;
 *   4. relax `unit_id` / `course_id` to nullable so a lesson can exist alone.
 *
 * The `units` table itself stays — Phase 5 retires it. Here lessons simply stop
 * being nested under a unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();
        });

        // Backfill: point each tenant's lessons at that tenant's Default academic
        // year (create one if the tenant somehow has none — e.g. added after the
        // 2026_08_04_000002 backfill seeded years).
        foreach (DB::table('lessons')->distinct()->pluck('tenant_id') as $tenantId) {
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

            DB::table('lessons')
                ->where('tenant_id', $tenantId)
                ->whereNull('academic_year_id')
                ->update(['academic_year_id' => $yearId]);
        }

        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_year_id')->nullable(false)->change();

            // Standalone lessons (LP-3): the unit/course link is no longer required.
            // Columns kept for the legacy runtime until Phase 5 retires units.
            $table->unsignedBigInteger('unit_id')->nullable()->change();
            $table->unsignedBigInteger('course_id')->nullable()->change();

            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'academic_year_id']);
            $table->dropConstrainedForeignId('academic_year_id');
            // Nullability relaxations are intentionally left in place (harmless).
        });
    }
};
