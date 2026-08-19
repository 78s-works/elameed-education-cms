<?php

namespace App\Modules\Assessment\Enums;

/**
 * The three kinds of exam (convention-gating model). The type fixes which content
 * link is required and how the exam participates in lesson progression:
 *
 *   lesson_quiz — bound to a Lesson (lesson_id auto-filled). Its submission is one
 *                 half of the "next lesson unlocks" gate.
 *   homework    — bound to a Lesson (same auto-fill). Its submission is the other
 *                 half of the gate. A lesson has at most one of each.
 *   free_exam   — bound to nothing. Open to any logged-in student, no enrollment.
 *
 * (`unit_exam` retired with the `courses`/units model — VD §7.)
 *
 * Exams are NEVER locked — every type is startable at any time (gating lives only
 * on lesson video content, see LessonProgressionService / ContentUnlockService).
 */
enum ExamType: string
{
    case LessonQuiz = 'lesson_quiz';
    case Homework = 'homework';
    case FreeExam = 'free_exam';

    /** May this type link to a Lesson (via lesson_id)? */
    public function linksLesson(): bool
    {
        return in_array($this, [self::LessonQuiz, self::Homework], true);
    }

    /**
     * Must a lesson link be present? Only a lesson_quiz is meaningless without a
     * lesson. Homework MAY link a lesson (auto-appears as a part) but can also be a
     * standalone "free homework" — still a homework, NOT a free_exam.
     */
    public function requiresLesson(): bool
    {
        return $this === self::LessonQuiz;
    }

    /** Standalone exam, reachable by any logged-in student without enrollment. */
    public function isFree(): bool
    {
        return $this === self::FreeExam;
    }
}
