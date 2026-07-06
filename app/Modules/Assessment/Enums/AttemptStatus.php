<?php

namespace App\Modules\Assessment\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';   // awaiting manual grading
    case Graded = 'graded';
}
