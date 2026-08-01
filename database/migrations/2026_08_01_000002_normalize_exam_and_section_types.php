<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convention-gating refactor — normalise existing data to the new 4-value
 * ExamType (lesson_quiz | homework | unit_exam | free_exam) and 4-value
 * LessonSectionType (lecture_video | pdf | quiz_solution | hw_solution).
 *
 * Single source of truth becomes Exam.lesson_id / Exam.unit_id. A quiz/assignment
 * section that hosted an exam lifts that exam UP to the lesson (copying lesson_id
 * + unit_id + the derived type onto the Exam), then the now-defunct hosting
 * section is removed. The reverse LessonSection.exam_id link is left dormant
 * (column kept, no longer written) per the "keep-dormant first" decision.
 *
 * Data-only + a column-default repoint. NON-REVERSIBLE (down is a no-op): the
 * old exam/section type strings and section rows are not reconstructed.
 *
 * Follows the cross-tenant data-migration precedent in
 * 2026_07_06_000006_reset_landing_layout_defaults (the migration DB role bypasses
 * RLS; on sqlite test runs RLS is a no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Lift each section-hosted exam onto its lesson (single source of truth):
        //    copy lesson_id + the lesson's unit_id, and derive the new exam type.
        $hosted = DB::table('lesson_sections')
            ->whereIn('type', ['quiz', 'assignment'])
            ->whereNotNull('exam_id')
            ->get(['lesson_id', 'exam_id', 'type']);

        foreach ($hosted as $section) {
            $unitId = DB::table('lessons')->where('id', $section->lesson_id)->value('unit_id');

            DB::table('exams')->where('id', $section->exam_id)->update([
                'lesson_id' => $section->lesson_id,
                'unit_id' => $unitId,
                'type' => $section->type === 'quiz' ? 'lesson_quiz' : 'homework',
            ]);
        }

        // 2) Map every remaining exam still on an OLD type string. Order matters:
        //    unit link wins, then a direct lesson link, then standalone -> free.
        DB::table('exams')->whereIn('type', ['exam', 'assignment'])
            ->whereNotNull('unit_id')->update(['type' => 'unit_exam']);

        DB::table('exams')->whereIn('type', ['exam', 'assignment'])
            ->whereNotNull('lesson_id')->update(['type' => 'lesson_quiz']);

        DB::table('exams')->whereIn('type', ['exam', 'assignment'])
            ->update(['type' => 'free_exam']);

        // 3) Convert section types. The generic assignment/solution video becomes a
        //    homework-solution video; the exam-hosting sections are gone (lifted).
        DB::table('lesson_sections')->where('type', 'assignment_video')
            ->update(['type' => 'hw_solution']);

        DB::table('lesson_sections')->whereIn('type', ['quiz', 'assignment'])->delete();

        // 4) Repoint the exams.type column default off the retired 'exam' value so a
        //    stray insert that omits type can't land an invalid enum string.
        if (Schema::hasColumn('exams', 'type')) {
            Schema::table('exams', function (Blueprint $table): void {
                $table->string('type')->default('free_exam')->change();
            });
        }
    }

    public function down(): void
    {
        // Non-reversible data normalisation — old type strings + lifted/removed
        // sections are not reconstructed. Column default repoint is harmless to keep.
    }
};
