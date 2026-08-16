<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `student_profiles` — extended student sign-up data. Squashed create (folds the
 * academic_year_id pin). `study_mode` and `center_id` are added by a trailing
 * migration because center_id FKs forward to `centers` (created later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
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
