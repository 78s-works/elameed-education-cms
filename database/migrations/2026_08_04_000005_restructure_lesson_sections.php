<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn lesson_sections into VD "parts" (doc 12 §7). The `type` column is reused
 * with the new authoring values (video|homework|quiz — see LessonSectionType);
 * these columns carry the new per-part config:
 *
 *   access_mode — the part's channel, constrained ⊆ its lesson's access_mode.
 *   delivery    — video_upload | image_upload | pdf_upload | bubble_sheet.
 *   gate_rule   — must_pass | must_submit (how the part gates the next one).
 *   max_tries   — retake cap per student (null = unlimited).
 *
 * Nullable throughout: legacy sections (lecture_video/pdf/solutions) leave them
 * NULL. The backing exam holds the degree/grading fields (see the exams table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->enum('access_mode', ['center', 'online', 'both'])->nullable()->after('type');
            $table->enum('delivery', ['video_upload', 'image_upload', 'pdf_upload', 'bubble_sheet'])->nullable()->after('access_mode');
            $table->enum('gate_rule', ['must_pass', 'must_submit'])->nullable()->after('delivery');
            $table->unsignedInteger('max_tries')->nullable()->after('gate_rule');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sections', function (Blueprint $table): void {
            $table->dropColumn(['access_mode', 'delivery', 'gate_rule', 'max_tries']);
        });
    }
};
