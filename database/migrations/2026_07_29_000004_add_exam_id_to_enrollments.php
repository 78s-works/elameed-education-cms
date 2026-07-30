<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * doc 11 R7 (decision D7) — a teacher can grant access to a single exam directly,
 * independent of a full-course enrollment. An enrollment row may now carry an
 * `exam_id` alongside course/unit/lesson (exactly one target). See
 * EnrollmentService::hasExamAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('exam_id')->nullable()->after('lesson_id')->constrained('exams')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropForeign(['exam_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'exam_id']);
            $table->dropColumn('exam_id');
        });
    }
};
