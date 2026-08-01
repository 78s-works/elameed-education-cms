<?php

namespace App\Modules\Assessment\Enums;

/**
 * The four kinds of exam (convention-gating model). The type fixes which content
 * link is required and how the exam participates in lesson progression:
 *
 *   lesson_quiz — bound to a Lesson (lesson_id + unit_id + course_id auto-filled).
 *                 Its submission is one half of the "next lesson unlocks" gate.
 *   homework    — bound to a Lesson (same auto-fill). Its submission is the other
 *                 half of the gate. A lesson has at most one of each.
 *   unit_exam   — bound to a Unit (unit_id + course_id). Never gates anything;
 *                 openable any time.
 *   free_exam   — bound to nothing (course_id optional). Open to any logged-in
 *                 student, no enrollment required.
 *
 * Exams are NEVER locked — every type is startable at any time (gating lives only
 * on lesson video content, see LessonProgressionService / ContentUnlockService).
 */
enum ExamType: string
{
    case LessonQuiz = 'lesson_quiz';
    case Homework = 'homework';
    case UnitExam = 'unit_exam';
    case FreeExam = 'free_exam';

    /** Must this type link to a Lesson (via lesson_id)? */
    public function linksLesson(): bool
    {
        return in_array($this, [self::LessonQuiz, self::Homework], true);
    }

    /** Must this type link to a Unit (via unit_id)? */
    public function linksUnit(): bool
    {
        return $this === self::UnitExam;
    }

    /** Standalone exam, reachable by any logged-in student without enrollment. */
    public function isFree(): bool
    {
        return $this === self::FreeExam;
    }
}
