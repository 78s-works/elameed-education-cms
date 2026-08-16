<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `questions` — exam question bank. Squashed create (folds the later
 * academic_year_id scoping column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained('exams')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();
            $table->string('type');
            $table->text('body')->nullable();
            $table->json('options')->nullable();
            $table->json('correct')->nullable();
            $table->unsignedInteger('points')->default(1);
            $table->json('book_ref')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'exam_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        TenantRls::enableFor('questions');
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
