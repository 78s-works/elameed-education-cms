<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exams` — exams & assignments (03_Data_Model.md §3 Assessment, FR-M08).
 * Tenant-scoped. Belongs to a course (and optionally a specific lesson).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();

            $table->string('title');
            $table->string('type')->default('exam');              // exam | assignment
            $table->unsignedTinyInteger('pass_percent')->default(50);
            $table->unsignedInteger('duration_min')->nullable();  // null = untimed
            $table->unsignedInteger('attempts_allowed')->default(1); // 0 = unlimited
            $table->string('question_order')->default('fixed');   // fixed | random
            $table->string('scoring')->default('best');           // best | last | first
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('result_visibility')->default('immediate'); // immediate | after_close | manual
            $table->boolean('show_answers')->default(false);
            $table->foreignId('depends_on_exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->string('mode')->default('standard');          // standard | bubble_sheet
            $table->boolean('is_published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id']);
        });

        TenantRls::enableFor('exams');
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
