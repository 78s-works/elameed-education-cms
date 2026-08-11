<?php

namespace App\Modules\Engagement\Enums;

/** Urgency a student assigns when opening a support ticket (M09, B24 / VD Item 11). */
enum TicketPriority: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';
}
