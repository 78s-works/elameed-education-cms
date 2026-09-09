<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Center sessions — a named, timed session held at a center that bundles 0+
 * lessons. Attendance is now taken against a session (not a lesson part): a
 * center check-in for a session opens all of the session's linked lessons online
 * for the student. Swaps `lesson_section_id` out of `attendance_records` for
 * `center_session_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->string('name');
            $table->dateTime('session_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'center_id']);
            $table->index(['tenant_id', 'academic_year_id']);
        });

        // A session bundles 0+ lessons (many-to-many).
        Schema::create('center_session_lesson', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('center_session_id')->constrained('center_sessions')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unique(['center_session_id', 'lesson_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('center_session_lesson');
        Schema::dropIfExists('center_sessions');
    }
};
