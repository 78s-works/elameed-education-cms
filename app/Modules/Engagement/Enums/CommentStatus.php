<?php

namespace App\Modules\Engagement\Enums;

/** Lifecycle of a lesson question/comment (M09, FR-M09-03). */
enum CommentStatus: string
{
    case New = 'new';
    case Answered = 'answered';
    case Closed = 'closed';
}
