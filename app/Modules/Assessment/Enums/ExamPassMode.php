<?php

namespace App\Modules\Assessment\Enums;

/**
 * How the "degree of success" threshold on an exam is expressed (VD change set
 * §7, LP-11). Teacher's choice per part:
 *
 *   percent — pass_value is a percentage 0–100.
 *   marks   — pass_value is an absolute mark count; requires total_marks and
 *             pass_value ≤ total_marks.
 */
enum ExamPassMode: string
{
    case Percent = 'percent';
    case Marks = 'marks';
}
