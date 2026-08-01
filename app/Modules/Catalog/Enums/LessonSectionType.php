<?php

namespace App\Modules\Catalog\Enums;

/**
 * Typed content section within a lesson (FR-M04-01). A lesson is composed of
 * ordered sections, each carrying exactly one MediaAsset payload:
 *   lecture_video — a lecture MediaAsset (media_asset_id) or YouTube link
 *   pdf           — a PDF/file MediaAsset (media_asset_id) with a pdf_kind
 *   quiz_solution — the answer video for this lesson's lesson_quiz. Hidden until
 *                   the student submits that quiz.
 *   hw_solution   — the answer video for this lesson's homework. Hidden until the
 *                   student submits that homework.
 *
 * Exams are NO LONGER hosted by sections — a lesson_quiz / homework Exam links to
 * the lesson directly (Exam.lesson_id). Sections only carry media now.
 */
enum LessonSectionType: string
{
    case LectureVideo = 'lecture_video';
    case Pdf = 'pdf';
    case QuizSolution = 'quiz_solution';
    case HwSolution = 'hw_solution';

    /** Every section points at a MediaAsset (video or pdf); none point at an exam. */
    public function usesMedia(): bool
    {
        return true;
    }

    /** Is this a video section? Video accepts an uploaded asset OR a YouTube link. */
    public function isVideo(): bool
    {
        return in_array($this, [self::LectureVideo, self::QuizSolution, self::HwSolution], true);
    }

    /** Is this a solution/answer video gated behind a matching exam submission? */
    public function isSolution(): bool
    {
        return in_array($this, [self::QuizSolution, self::HwSolution], true);
    }
}
