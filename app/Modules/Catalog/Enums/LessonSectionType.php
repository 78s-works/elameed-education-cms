<?php

namespace App\Modules\Catalog\Enums;

/**
 * Type of a lesson part (lesson_sections). Two generations coexist during the VD
 * migration (doc 12 §7); Phase 5 retires the legacy set.
 *
 * NEW authoring set (doc 12 §7 / doc 13 Phase 3) — what Teacher\LessonSectionController writes:
 *   video    — an uploaded lecture video (delivery=video_upload, media_asset_id).
 *   homework — an assignment part backed by an Exam (delivery + gate_rule + degree).
 *   quiz     — a quiz part backed by an Exam (same, plus an optional duration cap).
 *
 * LEGACY runtime set (doc 11) — still read by the student runtime + progression
 * (ContentUnlockService, StudentLessonSectionsController) until Phase 5:
 *   lecture_video — a lecture MediaAsset or YouTube link.
 *   pdf           — a PDF/file MediaAsset with a pdf_kind.
 *   quiz_solution — answer video, hidden until the lesson's lesson_quiz is submitted.
 *   hw_solution   — answer video, hidden until the lesson's homework is submitted.
 */
enum LessonSectionType: string
{
    // --- New authoring set (VD §7) -----------------------------------------
    case Video = 'video';
    case Homework = 'homework';
    case Quiz = 'quiz';

    // --- Legacy runtime set (doc 11, retired in Phase 5) -------------------
    case LectureVideo = 'lecture_video';
    case Pdf = 'pdf';
    case QuizSolution = 'quiz_solution';
    case HwSolution = 'hw_solution';

    /** The part types the new teacher authoring surface accepts. */
    public static function authoringValues(): array
    {
        return [self::Video->value, self::Homework->value, self::Quiz->value, self::Pdf->value];
    }

    /** A quiz/homework part is backed by an Exam row (holds the degree + grading). */
    public function backsExam(): bool
    {
        return $this === self::Homework || $this === self::Quiz;
    }

    /** Every legacy section points at a MediaAsset (video or pdf); none point at an exam. */
    public function usesMedia(): bool
    {
        return true;
    }

    /** Is this a video section? Video accepts an uploaded asset OR (legacy) a YouTube link. */
    public function isVideo(): bool
    {
        return in_array($this, [self::Video, self::LectureVideo, self::QuizSolution, self::HwSolution], true);
    }

    /** Is this a legacy solution/answer video gated behind a matching exam submission? */
    public function isSolution(): bool
    {
        return in_array($this, [self::QuizSolution, self::HwSolution], true);
    }
}
