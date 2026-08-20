<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `student_profiles` — extended student sign-up data. Squashed create (folds the
 * academic_year_id pin). `study_mode` and `center_id` are added by a trailing
 * migration because center_id FKs forward to `centers` (created later).
 *
 * `academic_year_id` is NOT NULL: every student-facing surface is year-scoped, so
 * an unpinned profile is not a valid state — LoginAction and ResolveAcademicYear
 * both refuse it, i.e. the row exists only to lock its owner out. The FK
 * therefore RESTRICTS on delete (a year with students pinned to it cannot be
 * deleted — AcademicYearController::destroy surfaces that as a 422) instead of
 * the old nullOnDelete, which quietly unpinned and locked out every student of a
 * deleted year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('gender')->nullable();
            $table->string('governorate')->nullable();
            $table->string('region')->nullable();
            $table->string('academic_year')->nullable();
            $table->string('education_type')->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('tenant_id');
            $table->index(['tenant_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
