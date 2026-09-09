<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_attempts` — a student's attempt at an exam. Squashed create (folds the
 * later feedback / corrected_file / needs_manual_grade fields and the
 * academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('max_score')->nullable();
            $table->string('status')->default('in_progress');
            $table->json('answers')->nullable();
            $table->text('feedback')->nullable();
            $table->json('corrected_file')->nullable();
            $table->boolean('needs_manual_grade')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'exam_id', 'user_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('exam_attempts');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
