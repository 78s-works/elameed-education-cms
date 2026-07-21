<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds lesson-level access alongside course + unit: an enrollment row can now grant
 * a whole course (`course_id`), a unit (`unit_id`), or a single lesson (`lesson_id`).
 * A course enrollment still opens every lesson in the course; a unit enrollment opens
 * that chapter's lessons; a lesson enrollment opens just that one lesson. See
 * EnrollmentService::hasLessonAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('unit_id')->constrained('lessons')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'lesson_id']);
            $table->dropColumn('lesson_id');
        });
    }
};
