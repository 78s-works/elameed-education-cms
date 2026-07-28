<?php

namespace App\Modules\Catalog\Enums;

/**
 * Typed content section within a lesson (FR-M04-01, "Flexible Lesson Content
 * Structure"). A lesson is composed of ordered sections, each carrying exactly
 * one payload:
 *   lecture_video    — a lecture MediaAsset (media_asset_id)
 *   assignment_video — a solution/assignment MediaAsset (media_asset_id)
 *   pdf              — a PDF/file MediaAsset (media_asset_id) with a pdf_kind
 *   assignment       — an Exam of type=assignment (exam_id), student submits
 *   quiz             — an Exam of type=exam (exam_id)
 */
enum LessonSectionType: string
{
    case LectureVideo = 'lecture_video';
    case AssignmentVideo = 'assignment_video';
    case Pdf = 'pdf';
    case Assignment = 'assignment';
    case Quiz = 'quiz';

    /** Does this section point at a MediaAsset (vs an Exam)? */
    public function usesMedia(): bool
    {
        return in_array($this, [self::LectureVideo, self::AssignmentVideo, self::Pdf], true);
    }

    /** Does this section point at an Exam/assignment? */
    public function usesExam(): bool
    {
        return in_array($this, [self::Assignment, self::Quiz], true);
    }
}
