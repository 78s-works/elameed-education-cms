<?php

namespace App\Modules\Engagement\Enums;

/** Lifecycle of a support ticket (M09, B24 / VD Item 11). */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
