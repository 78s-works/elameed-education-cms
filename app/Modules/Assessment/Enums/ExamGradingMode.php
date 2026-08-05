<?php

namespace App\Modules\Assessment\Enums;

/**
 * Who scores the attempt (VD change set §7, LP-12):
 *
 *   manual — a teacher/assistant grades it. Forced for file/image/pdf uploads.
 *   auto   — the system scores it against the answer key. Allowed only when the
 *            backing part's delivery is a bubble sheet.
 */
enum ExamGradingMode: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
