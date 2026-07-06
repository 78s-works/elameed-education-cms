<?php

namespace App\Modules\Assessment\Enums;

/**
 * Question types (FR-M08). `mcq` and `true_false` auto-grade; `short`, `essay`,
 * `file` need manual grading. Bubble-sheet questions are `mcq` with a `book_ref`
 * and no body.
 */
enum QuestionType: string
{
    case Mcq = 'mcq';
    case TrueFalse = 'true_false';
    case Short = 'short';
    case Essay = 'essay';
    case File = 'file';

    /** Can this type be graded automatically on submit? */
    public function isAutoGraded(): bool
    {
        return $this === self::Mcq || $this === self::TrueFalse;
    }
}
