<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `enrollments` — a student's access grant to content. Squashed create: folds
 * unit/lesson access columns and academic_year_id. `unit_id` and `bundle_id` are
 * dormant FK-less columns (Units/Bundles retired) keeping their legacy index
 * names. `exam_id` and `package_id` are added by trailing migrations because they
 * FK forward to tables created later (exams / packages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->unsignedBigInteger('bundle_id')->nullable();
            $table->string('source')->default('purchase');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'course_id']);
            $table->index('unit_id', 'enrollments_unit_id_foreign');
            $table->index('bundle_id', 'enrollments_bundle_id_foreign');
            $table->index(['tenant_id', 'user_id', 'unit_id']);
            $table->index(['tenant_id', 'user_id', 'lesson_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('enrollments');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
