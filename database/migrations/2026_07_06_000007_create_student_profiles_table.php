<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-academy student registration details (the fields from the sign-up form:
 * gender, governorate, region, academic year, education type, guardian phone).
 * Scoped per (tenant, student) so each teacher owns and edits their students'
 * data independently — name/phone/password stay on the shared user identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('gender')->nullable();          // ذكر | أنثى (free text)
            $table->string('governorate')->nullable();     // المحافظة
            $table->string('region')->nullable();          // المنطقة
            $table->string('academic_year')->nullable();    // السنة الدراسية
            $table->string('education_type')->nullable();   // نوع التعليم
            $table->string('guardian_phone', 30)->nullable(); // هاتف ولي الأمر
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
