<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exams` — assessments linked to a lesson (or free-standing). Squashed create:
 * folds the lesson link, time-extension cap, degree/grading fields, and
 * academic_year_id. (`courses`/units retired — VD §7; no course_id/unit_id.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('free_exam');
            $table->unsignedTinyInteger('pass_percent')->default(50);
            $table->enum('pass_mode', ['percent', 'marks'])->default('percent');
            $table->decimal('pass_value', 8, 2)->nullable();
            $table->decimal('total_marks', 8, 2)->nullable();
            $table->enum('grading_mode', ['manual', 'auto'])->default('manual');
            $table->unsignedInteger('duration_min')->nullable();
            $table->unsignedInteger('max_time_extensions')->default(0);
            $table->unsignedInteger('attempts_allowed')->default(1);
            $table->string('question_order')->default('fixed');
            $table->string('scoring')->default('best');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('result_visibility')->default('immediate');
            $table->boolean('show_answers')->default(false);
            $table->foreignId('depends_on_exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->string('mode')->default('standard');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lesson_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('exams');
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
