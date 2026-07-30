<?php

namespace App\Modules\Catalog\Enums;

/**
 * What action on the prerequisite section unlocks the dependent one
 * ("Content Dependencies & Unlock Rules"):
 *   submitted  — an attempt on the prerequisite exam/assignment was submitted
 *   passed     — the prerequisite exam/assignment was passed (>= pass_percent)
 *   completed  — the prerequisite lesson (video) was watched to completion
 *   graded     — the prerequisite assignment was graded/corrected by staff
 *                (exam_attempts.status = graded) — doc 11 R5.2 homework gate
 */
enum DependencyTrigger: string
{
    case Submitted = 'submitted';
    case Passed = 'passed';
    case Completed = 'completed';
    case Graded = 'graded';
}
