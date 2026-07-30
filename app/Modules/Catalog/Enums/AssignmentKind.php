<?php

namespace App\Modules\Catalog\Enums;

/**
 * How an `assignment` lesson section is answered (doc 11 R3.4):
 *   upload — student uploads a homework file; a teacher/assistant grades it
 *            ("corrected"). This is the part that gates the next lesson (R5.2).
 *   onsite — answered in the browser as a normal exam/assignment attempt.
 */
enum AssignmentKind: string
{
    case Upload = 'upload';
    case Onsite = 'onsite';
}
