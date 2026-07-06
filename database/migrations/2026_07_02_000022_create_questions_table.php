<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `questions` (03_Data_Model.md §3). `exam_id` NULL = a reusable question-bank
 * item; set = attached to that exam. `body` may be null in bubble-sheet mode
 * (the question lives in a printed book, referenced by `book_ref`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained('exams')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();

            $table->string('type');                    // mcq | true_false | short | essay | file
            $table->text('body')->nullable();
            $table->json('options')->nullable();       // e.g. ["A","B","C","D"]
            $table->json('correct')->nullable();       // e.g. [1]  (indices) — hidden from students
            $table->unsignedInteger('points')->default(1);
            $table->json('book_ref')->nullable();      // {book,page,qno} for bubble-sheet
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'exam_id']);
        });

        TenantRls::enableFor('questions');
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
