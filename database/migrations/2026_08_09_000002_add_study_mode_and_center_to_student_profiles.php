<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5 (VD R6/R7) — hybrid center/online identity on the student sign-up profile.
 *
 *   study_mode — how the student attends: center (on-site), online (remote), or
 *                both. Same vocabulary as Catalog\Enums\AccessMode so a student's
 *                mode reads against a lesson/part's access_mode without a mapping.
 *   center_id  — the physical center a center/both student belongs to; nullable
 *                FK → centers (online students have none). nullOnDelete so
 *                removing a center never destroys the student's profile row.
 *
 * guardian_phone is intentionally NOT touched here: it was never given a unique
 * index (see 2026_07_06_000007_create_student_profiles_table), so siblings can
 * already share one number. The acceptance criterion "non-unique" is met as-built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->enum('study_mode', ['center', 'online', 'both'])
                ->default('online')
                ->after('guardian_phone'); // نظام الدراسة
            $table->foreignId('center_id')
                ->nullable()
                ->after('study_mode')
                ->constrained('centers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropForeign(['center_id']);
            $table->dropColumn(['center_id', 'study_mode']);
        });
    }
};
