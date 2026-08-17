<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `enrollments` — a student's access grant to content. Squashed create: folds the
 * lesson access column and academic_year_id. Access is per-lesson (or per-exam);
 * a package fan-out writes per-lesson rows tagged with `package_id`. (`courses`/
 * units/bundles retired — VD §7; no course_id/unit_id/bundle_id.) `exam_id` and
 * `package_id` are added by trailing migrations because they FK forward to tables
 * created later (exams / packages).
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
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->string('source')->default('purchase');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

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
