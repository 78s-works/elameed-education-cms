<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make STUDENTS and ASSISTANTS academic-year-dependent (like lessons/packages).
 *
 *  - Students already carry `student_profiles.academic_year_id` (the pin the
 *    ResolveAcademicYear middleware reads). We backfill any legacy NULL pins to
 *    the tenant's first year so no student is left year-less (null rows are
 *    disallowed going forward — enforced at the app layer on create/update).
 *  - Assistants get a many-to-many link to years via a pivot: one assistant
 *    membership can serve several years at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Assistant ↔ academic-year pivot (many-to-many).
        // No tenant_id column: the tenant is derivable via tenant_user, and
        // belongsToMany::sync() doesn't populate extra non-default columns.
        Schema::create('assistant_academic_year', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_user_id')->constrained('tenant_user')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_user_id', 'academic_year_id']);
            $table->index('academic_year_id');
        });

        // 2) Backfill legacy student pins so none are left null.
        $nullPins = DB::table('student_profiles')->whereNull('academic_year_id')->get(['id', 'tenant_id']);
        foreach ($nullPins as $row) {
            $yearId = DB::table('academic_years')
                ->where('tenant_id', $row->tenant_id)
                ->orderBy('sort_order')->orderBy('id')
                ->value('id');
            if ($yearId !== null) {
                DB::table('student_profiles')->where('id', $row->id)->update(['academic_year_id' => $yearId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_academic_year');
    }
};
