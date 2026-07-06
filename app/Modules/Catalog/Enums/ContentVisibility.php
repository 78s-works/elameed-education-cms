<?php

namespace App\Modules\Catalog\Enums;

/**
 * Visibility of a course / unit / lesson (FR-M04-05).
 *   visible   — publicly listed (subject to publish_at)
 *   hidden    — not listed
 *   scheduled — becomes visible at publish_at
 */
enum ContentVisibility: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
    case Scheduled = 'scheduled';
}
