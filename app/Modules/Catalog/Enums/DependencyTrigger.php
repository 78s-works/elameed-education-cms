<?php

namespace App\Modules\Catalog\Enums;

/**
 * What action on the prerequisite section unlocks the dependent one
 * ("Content Dependencies & Unlock Rules"):
 *   submitted  — an attempt on the prerequisite exam/assignment was submitted
 *   passed     — the prerequisite exam/assignment was passed (>= pass_percent)
 *   completed  — the prerequisite lesson (video) was watched to completion
 */
enum DependencyTrigger: string
{
    case Submitted = 'submitted';
    case Passed = 'passed';
    case Completed = 'completed';
}
