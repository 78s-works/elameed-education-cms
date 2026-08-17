<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section-level attendance (center check-in → time-boxed online access). Adds a
 * `lesson_section_id` (which part the student checked in for) and an
 * `access_expires_at` snapshot (when the granted online window ends), and widens
 * the per-day uniqueness to include the section so one student can check in for
 * several parts on the same day. Legacy day-attendance rows keep
 * `lesson_section_id = null`; the day controller pins that null so it never
 * collides with a section row.
 *
 * Ordering matters: the new unique is created BEFORE the old one is dropped —
 * the old `(center_id, user_id, attended_on)` index is the supporting index for
 * the `center_id` foreign key, so MySQL refuses to drop it until another
 * center_id-leading index exists (the new one qualifies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_records', 'lesson_section_id')) {
                $table->foreignId('lesson_section_id')->nullable()->after('course_id')
                    ->constrained('lesson_sections')->nullOnDelete();
            }
            if (! Schema::hasColumn('attendance_records', 'access_expires_at')) {
                $table->timestamp('access_expires_at')->nullable()->after('lesson_section_id');
            }
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unique(
                ['center_id', 'user_id', 'attended_on', 'lesson_section_id'],
                'attendance_center_user_day_section_unique',
            );
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('attendance_records_center_id_user_id_attended_on_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unique(['center_id', 'user_id', 'attended_on']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('attendance_center_user_day_section_unique');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_section_id');
            $table->dropColumn('access_expires_at');
        });
    }
};
