<?php

use App\Support\Rls\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_attempts` (03_Data_Model.md §3). Answers are stored denormalised as a
 * JSON blob on the attempt (per the data model), with a `needs_manual_grade`
 * flag when subjective questions await a teacher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('score')->nullable();      // points awarded
            $table->unsignedInteger('max_score')->nullable();  // points possible
            $table->string('status')->default('in_progress');  // in_progress|submitted|graded
            $table->json('answers')->nullable();               // { qid: {answer, awarded, is_correct} }
            $table->boolean('needs_manual_grade')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'exam_id', 'user_id']);
        });

        TenantRls::enableFor('exam_attempts');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
