<?php

namespace App\Modules\Assessment\Enums;

/**
 * `standard` — questions shown on screen. `bubble_sheet` — questions live in a
 * printed book (FR-M08-03); the app shows only the reference + choice letters.
 */
enum ExamMode: string
{
    case Standard = 'standard';
    case BubbleSheet = 'bubble_sheet';
}
